<?php
/**
 * Cloudflare GraphQL Analytics API (httpRequestsAdaptiveGroups) から
 * 日次の集計アクセスログを生成する。
 *
 * 共用レンタルサーバーのcron実行時間制限（一般的に約5分）に収まるよう、
 * チェックポイント方式で動作する。1回の実行が MAX_RUNTIME_SECONDS に近づいたら
 * state.json に進捗を保存していったん終了し、次回のcron実行で続きから再開する。
 *
 * 想定運用: run.sh から10分ごと等の高頻度cronで起動する。前日分の処理が
 * 完了していれば何もせず即終了するので、高頻度に呼んでも負荷は小さい。
 *
 * 出力:
 *   <OUTPUT_DIR>/daily/<date>/<site>-aggregated-access-<date>.full.jsonl.gz     全件・正本
 *   <OUTPUT_DIR>/daily/<date>/<site>-aggregated-access-<date>.filtered.log.gz   静的アセット除外済み
 *   <OUTPUT_DIR>/daily/<date>/<site>-aggregated-access-<date>.summary.json      実行結果メタデータ
 *
 * 動作要件: PHP CLI 7.4以上、curl拡張・zlib拡張（多くの共用サーバーで標準有効）
 */

// 出力ログにはIP・User-Agent等が含まれるため、run.sh経由でなく直接
// `php cf_access_log_ga_free.php` された場合でも他ユーザーから読めないようにする
umask(0077);

foreach (['curl', 'zlib', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[error] PHP拡張 '{$ext}' が有効になっていません。サーバー管理者にご確認ください。\n");
        exit(1);
    }
}

define('SCRIPT_DIR', __DIR__);
// テスト時のみ CF_ACCESS_LOG_GA_FREE_API_BASE でモックサーバーに向けられる（本番では未設定）
define('API_BASE', getenv('CF_ACCESS_LOG_GA_FREE_API_BASE') ?: 'https://api.cloudflare.com/client/v4');
define('GRAPHQL_URL', API_BASE . '/graphql');

define('ROW_LIMIT', 10000);
define('SPLIT_STEPS_MINUTES', [60, 30, 15, 5, 1]);
define('QUERY_PACING_SECONDS', 0.3);
define('STATE_SCHEMA_VERSION', 1);

// filtered.log から除外する静的アセットの拡張子。
// PDFはパンフレット等ダウンロードのコンバージョン価値があるため除外対象に含めない。
define('STATIC_ASSET_EXTENSIONS', [
    'js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
    'woff', 'woff2', 'ttf', 'eot', 'map', 'mp4',
]);

const DIMENSIONS_QUERY = <<<'GRAPHQL'
query DailyAgg($zoneTag: string!, $start: Time!, $end: Time!, $limit: Int!) {
  viewer {
    zones(filter: {zoneTag: $zoneTag}) {
      httpRequestsAdaptiveGroups(
        limit: $limit
        filter: { datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball" }
        orderBy: [datetimeMinute_ASC, clientIP_ASC, clientRequestPath_ASC]
      ) {
        count
        avg { sampleInterval }
        sum { edgeResponseBytes }
        dimensions {
          datetimeMinute
          clientIP
          clientRequestHTTPMethodName
          clientRequestPath
          clientRequestHTTPProtocol
          edgeResponseStatus
          userAgent
          clientCountryName
        }
      }
    }
  }
}
GRAPHQL;

class RetryableAPIError extends Exception {}

function jst_tz(): DateTimeZone { return new DateTimeZone('Asia/Tokyo'); }
function utc_tz(): DateTimeZone { return new DateTimeZone('UTC'); }

function now_iso(): string {
    return (new DateTimeImmutable('now', utc_tz()))->format('Y-m-d\TH:i:s\Z');
}

function parse_iso(string $s): DateTimeImmutable {
    return DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $s, utc_tz());
}

function fail(string $message): void {
    fwrite(STDERR, "[error] {$message}\n");
    exit(1);
}

function resolve_path(string $base, string $path): string {
    $isAbsolute = $path !== '' && $path[0] === '/';
    $combined = $isAbsolute ? $path : $base . '/' . $path;
    $resolved = [];
    foreach (explode('/', $combined) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $part;
    }
    return '/' . implode('/', $resolved);
}

function load_config(string $path): array {
    if (!file_exists($path)) {
        fail("設定ファイルが見つかりません: {$path}\n        config.env.example を config.env にコピーして値を設定してください");
    }
    $raw = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        $value = trim($value, "\"'");
        $raw[trim($key)] = $value;
    }

    $require = function (string $key) use ($raw): string {
        $v = $raw[$key] ?? '';
        if ($v === '') {
            fail("設定ファイルに {$key} が設定されていません");
        }
        return $v;
    };

    $positiveInt = function (string $key, int $default) use ($raw): int {
        $v = $raw[$key] ?? '';
        if ($v === '') {
            return $default;
        }
        if (!ctype_digit($v)) {
            fail("設定 {$key} は整数で指定してください（現在の値: '{$v}'）");
        }
        $n = (int)$v;
        if ($n < 1) {
            fail("設定 {$key} は1以上にしてください（現在の値: '{$v}'）");
        }
        return $n;
    };

    $zoneName = $require('CF_ZONE_NAME');
    $maxRuntimeSeconds = $positiveInt('MAX_RUNTIME_SECONDS', 240);
    $safetyMarginSeconds = 30;
    if ($maxRuntimeSeconds <= $safetyMarginSeconds) {
        fail("MAX_RUNTIME_SECONDS は{$safetyMarginSeconds}より大きくしてください（現在の値: {$maxRuntimeSeconds}）");
    }

    $outputDirRaw = ($raw['OUTPUT_DIR'] ?? '') ?: './data';
    $outputDir = resolve_path(SCRIPT_DIR, $outputDirRaw);

    return [
        'cf_api_token' => $require('CF_API_TOKEN'),
        'cf_zone_name' => $zoneName,
        'site_name' => ($raw['SITE_NAME'] ?? '') ?: $zoneName,
        'retention_days' => $positiveInt('RETENTION_DAYS', 90),
        'target_days_ago' => $positiveInt('TARGET_DAYS_AGO', 1),
        'output_dir' => $outputDir,
        'max_runtime_seconds' => $maxRuntimeSeconds,
        'max_queries_per_run' => $positiveInt('MAX_QUERIES_PER_RUN', 200),
        'safety_margin_seconds' => $safetyMarginSeconds,
    ];
}

function state_path_for(array $config): string {
    return $config['output_dir'] . '/state.json';
}

function load_state(array $config): ?array {
    $path = state_path_for($config);
    if (!file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    $state = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fail(
            "{$path} が壊れています（JSONとして読めません）。\n" .
            "        このファイルを退避（リネーム等）してから再実行してください。１日分の処理は最初からやり直しになります。"
        );
    }
    return $state;
}

function save_state_atomic(array &$state, array $config): void {
    $path = state_path_for($config);
    @mkdir(dirname($path), 0755, true);
    $state['runtime']['updated_at'] = now_iso();
    $tmpPath = $path . '.tmp';
    file_put_contents($tmpPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename($tmpPath, $path);
}

/**
 * @throws RetryableAPIError
 */
function api_request(string $url, string $token, array $payload): array {
    $body = json_encode($payload);
    $lastErr = null;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($response === false) {
            $lastErr = $curlErr ?: 'unknown curl error';
            usleep(3_000_000);
            continue;
        }
        if ($httpCode === 429 || ($httpCode >= 500 && $httpCode < 600)) {
            $lastErr = "HTTP {$httpCode}: {$response}";
            usleep(3_000_000);
            continue;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            fail("Cloudflare APIへのリクエストに失敗しました(HTTP {$httpCode}): {$response}");
        }
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            fail("Cloudflare APIのレスポンスがJSONとして解析できません: " . json_last_error_msg());
        }
        return $decoded;
    }

    throw new RetryableAPIError("APIリクエストが失敗（次回cronで再試行）: {$lastErr}");
}

function resolve_zone_id(string $token, string $zoneName): string {
    $ch = curl_init(API_BASE . '/zones?name=' . urlencode($zoneName));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($response === false) {
        fail("Cloudflare APIへ接続できません（ネットワークを確認してください）: {$curlErr}");
    }
    if ($httpCode === 401 || $httpCode === 403) {
        fail(
            "Cloudflare APIトークンが無効か、権限が不足しています(HTTP {$httpCode})。\n" .
            "        config.env の CF_API_TOKEN と、トークンの Zone Read 権限を確認してください。\n" .
            "        詳細: {$response}"
        );
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        fail("Cloudflare APIへの接続に失敗しました(HTTP {$httpCode}): {$response}");
    }

    $data = json_decode($response, true);
    $result = $data['result'] ?? [];
    if (empty($result)) {
        fail(
            "ゾーン '{$zoneName}' がこのAPIトークンから見つかりません。\n" .
            "        config.env の CF_ZONE_NAME が正しいか、トークンにこのゾーンへのアクセス権限があるか確認してください。"
        );
    }
    return $result[0]['id'];
}

/**
 * @throws RetryableAPIError
 */
function query_window(string $token, string $zoneId, DateTimeImmutable $start, DateTimeImmutable $end): array {
    $payload = [
        'query' => DIMENSIONS_QUERY,
        'variables' => [
            'zoneTag' => $zoneId,
            'start' => $start->setTimezone(utc_tz())->format('Y-m-d\TH:i:s\Z'),
            'end' => $end->setTimezone(utc_tz())->format('Y-m-d\TH:i:s\Z'),
            'limit' => ROW_LIMIT,
        ],
    ];
    $result = api_request(GRAPHQL_URL, $token, $payload);

    if (!empty($result['errors'])) {
        $messages = implode(' / ', array_map(fn($e) => $e['message'] ?? json_encode($e), $result['errors']));
        $lower = strtolower($messages);
        if (strpos($lower, 'access') !== false || strpos($lower, 'permission') !== false || strpos($lower, 'not authorized') !== false) {
            fail(
                "Cloudflare APIトークンに、このデータを取得する権限がありません。\n" .
                "        トークンに Zone > Analytics > Read 権限が付与されているか確認してください。\n" .
                "        詳細: {$messages}"
            );
        }
        // 権限エラー以外(クエリ不正・スキーマ変更等)は一時的な障害ではないため、
        // 無限リトライさせずここで停止する（python版と同じ挙動）
        fail("GraphQLエラー: {$messages}");
    }

    return $result['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [];
}

function normalize_row(array $row): array {
    $d = $row['dimensions'];
    $dtUtc = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $d['datetimeMinute'], utc_tz());
    $dtJst = $dtUtc->setTimezone(jst_tz());
    return [
        'count' => $row['count'],
        'sampleInterval' => $row['avg']['sampleInterval'],
        'edgeResponseBytes' => $row['sum']['edgeResponseBytes'],
        'datetimeUTC' => $d['datetimeMinute'],
        'datetimeJST' => $dtJst->format('Y-m-d\TH:i:sP'),
        'clientIP' => $d['clientIP'],
        'country' => $d['clientCountryName'],
        'method' => $d['clientRequestHTTPMethodName'],
        'path' => $d['clientRequestPath'],
        'protocol' => $d['clientRequestHTTPProtocol'],
        'status' => $d['edgeResponseStatus'],
        'userAgent' => $d['userAgent'],
    ];
}

function is_static_asset(string $path): bool {
    $path = explode('?', $path, 2)[0];
    $path = explode('#', $path, 2)[0];
    $segments = explode('/', $path);
    $lastSegment = end($segments);
    if (strpos($lastSegment, '.') === false) {
        return false;
    }
    $parts = explode('.', $path);
    $ext = strtolower(end($parts));
    return in_array($ext, STATIC_ASSET_EXTENSIONS, true);
}

function format_log_line(array $r): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $r['datetimeJST']);
    $ts = $dt->format('d/M/Y:H:i:s +0900');
    return sprintf(
        'count=%s sample_interval=%s bytes=%s %s - %s [%s] "%s %s %s" %s "%s"',
        $r['count'], $r['sampleInterval'], $r['edgeResponseBytes'], $r['clientIP'], $r['country'],
        $ts, $r['method'], $r['path'], $r['protocol'], $r['status'], $r['userAgent']
    );
}

function part_filename(array $window): string {
    // windowの開始時刻から決まる名前にすることで、同じwindowを再処理しても
    // 上書きになり重複が起きない（cronがkillされた場合の再実行に対して冪等）
    // 中間partは非圧縮テキストで保持する（gzdecode()はPHPでは複数gzipメンバーを
    // 連結したストリームの2つ目以降を読めないため、圧縮はfinalize時に1回だけ行う）
    return str_replace(':', '', $window['start']) . '.part';
}

function write_part_atomic(string $path, array $lines): void {
    @mkdir(dirname($path), 0755, true);
    $content = implode("\n", $lines);
    if ($lines) {
        $content .= "\n";
    }
    $tmpPath = $path . '.tmp';
    file_put_contents($tmpPath, $content);
    rename($tmpPath, $path);
}

/**
 * partファイル群を単一のgzipメンバーへストリーミング結合しつつ行数を数える。
 * 全partを一度にメモリへ載せず、part単位（最大でも1window分=ROW_LIMIT行程度）で
 * 逐次gzwrite()するため、日次データ全体のメモリ肥大化を避けられる。
 */
function concat_parts(string $partsDir, string $dest): int {
    @mkdir(dirname($dest), 0755, true);
    $tmpPath = $dest . '.tmp';
    $gz = gzopen($tmpPath, 'wb9');
    $lineCount = 0;
    if (is_dir($partsDir)) {
        $files = glob($partsDir . '/*.part');
        sort($files);
        foreach ($files as $part) {
            $content = file_get_contents($part);
            gzwrite($gz, $content);
            $lineCount += substr_count($content, "\n");
        }
    }
    gzclose($gz);
    rename($tmpPath, $dest);
    return $lineCount;
}

function day_bounds_utc(string $dateStr): array {
    $startJst = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr, jst_tz())->setTime(0, 0, 0);
    $endJst = $startJst->modify('+1 day');
    return [$startJst->setTimezone(utc_tz()), $endJst->setTimezone(utc_tz())];
}

function build_initial_queue(DateTimeImmutable $dayStart, DateTimeImmutable $dayEnd): array {
    $queue = [];
    $cursor = $dayStart;
    while ($cursor < $dayEnd) {
        $windowEnd = min($cursor->modify('+60 minutes'), $dayEnd);
        $queue[] = [
            'start' => $cursor->format('Y-m-d\TH:i:s\Z'),
            'end' => $windowEnd->format('Y-m-d\TH:i:s\Z'),
            'window_minutes' => 60,
            'attempts' => 0,
        ];
        $cursor = $windowEnd;
    }
    return $queue;
}

function next_split_minutes(int $currentMinutes): ?int {
    $steps = SPLIT_STEPS_MINUTES;
    $idx = array_search($currentMinutes, $steps, true);
    if ($idx === false || $idx + 1 >= count($steps)) {
        return null;
    }
    return $steps[$idx + 1];
}

function split_window(array $window, int $minutes): array {
    $start = parse_iso($window['start']);
    $end = parse_iso($window['end']);
    $sub = [];
    $cursor = $start;
    while ($cursor < $end) {
        $subEnd = min($cursor->modify("+{$minutes} minutes"), $end);
        $sub[] = [
            'start' => $cursor->format('Y-m-d\TH:i:s\Z'),
            'end' => $subEnd->format('Y-m-d\TH:i:s\Z'),
            'window_minutes' => $minutes,
            'attempts' => 0,
        ];
        $cursor = $subEnd;
    }
    return $sub;
}

function initialize_state(string $targetDate, string $zoneId, array $config): array {
    [$dayStart, $dayEnd] = day_bounds_utc($targetDate);
    $workDir = $config['output_dir'] . '/work/' . $targetDate;
    if (is_dir($workDir)) {
        remove_dir_recursive($workDir);
    }
    @mkdir($workDir, 0755, true);
    $finalDir = $config['output_dir'] . '/daily/' . $targetDate;

    return [
        'schema_version' => STATE_SCHEMA_VERSION,
        'status' => 'running',
        'site_name' => $config['site_name'],
        'zone_id' => $zoneId,
        'target_date' => $targetDate,
        'day_start' => $dayStart->format('Y-m-d\TH:i:s\Z'),
        'day_end' => $dayEnd->format('Y-m-d\TH:i:s\Z'),
        'queue' => build_initial_queue($dayStart, $dayEnd),
        'completed_windows' => [],
        'totals' => [
            'queries' => 0,
            'raw_rows' => 0,
            'filtered_rows' => 0,
            'api_errors' => 0,
            'split_count' => 0,
        ],
        'warnings' => [],
        'output' => [
            'work_dir' => $workDir,
            'full_parts_dir' => $workDir . '/parts/full',
            'filtered_parts_dir' => $workDir . '/parts/filtered',
            'final_dir' => $finalDir,
        ],
        'runtime' => [
            'started_at' => now_iso(),
            'updated_at' => now_iso(),
            'last_success_at' => null,
            'last_error' => null,
        ],
        'last_completed_date' => null,
    ];
}

function determine_target_date(?array $state, array $config): ?string {
    $boundary = (new DateTimeImmutable('now', jst_tz()))->modify("-{$config['target_days_ago']} day")->format('Y-m-d');
    if ($state === null) {
        return $boundary;
    }
    if (in_array($state['status'], ['running', 'partial'], true)) {
        return $state['target_date'];
    }
    $lastCompleted = $state['last_completed_date'] ?? null;
    if ($lastCompleted === null) {
        return $boundary;
    }
    $nextDate = (DateTimeImmutable::createFromFormat('Y-m-d', $lastCompleted, jst_tz()))->modify('+1 day')->format('Y-m-d');
    if ($nextDate <= $boundary) {
        return $nextDate;
    }
    return null;
}

function finalize_outputs(array $state): void {
    $finalDir = $state['output']['final_dir'];
    @mkdir($finalDir, 0755, true);
    $site = $state['site_name'];
    $dateStr = $state['target_date'];

    $fullFinal = "{$finalDir}/{$site}-aggregated-access-{$dateStr}.full.jsonl.gz";
    $filteredFinal = "{$finalDir}/{$site}-aggregated-access-{$dateStr}.filtered.log.gz";
    $summaryFinal = "{$finalDir}/{$site}-aggregated-access-{$dateStr}.summary.json";

    // part再書き込み(クラッシュ後の再実行)があってもtotalsの累積値はズレうるため、
    // 結合と同時に数えた実際の行数を正本とする
    $aggregatedRows = concat_parts($state['output']['full_parts_dir'], $fullFinal);
    $filteredRows = concat_parts($state['output']['filtered_parts_dir'], $filteredFinal);

    $summary = [
        'site_name' => $site,
        'zone_id' => $state['zone_id'],
        'date_jst' => $dateStr,
        'period_start_utc' => $state['day_start'],
        'period_end_utc' => $state['day_end'],
        'aggregated_rows' => $aggregatedRows,
        'filtered_rows' => $filteredRows,
        'api_query_count' => $state['totals']['queries'],
        'split_count' => $state['totals']['split_count'],
        'warnings' => $state['warnings'],
        'generated_at' => now_iso(),
    ];
    $summaryTmp = $summaryFinal . '.tmp';
    file_put_contents($summaryTmp, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename($summaryTmp, $summaryFinal);

    if (is_dir($state['output']['work_dir'])) {
        remove_dir_recursive($state['output']['work_dir']);
    }
}

function cleanup_old_logs(array $config): void {
    $dailyDir = $config['output_dir'] . '/daily';
    if (!is_dir($dailyDir)) {
        return;
    }
    $cutoff = (new DateTimeImmutable('now', jst_tz()))->modify("-{$config['retention_days']} day")->format('Y-m-d');
    foreach (scandir($dailyDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $entryPath = $dailyDir . '/' . $entry;
        if (is_dir($entryPath) && $entry < $cutoff) {
            remove_dir_recursive($entryPath);
            echo "[info] 保存期間({$config['retention_days']}日)超過のため削除: {$entryPath}\n";
        }
    }
}

function remove_dir_recursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function run_once(array $config): void {
    $state = load_state($config);
    $targetDate = determine_target_date($state, $config);
    if ($targetDate === null) {
        echo "[info] 処理対象の新しい日付なし。終了\n";
        return;
    }

    if ($state === null || $state['status'] === 'completed' || ($state['target_date'] ?? null) !== $targetDate) {
        $token = $config['cf_api_token'];
        $zoneId = resolve_zone_id($token, $config['cf_zone_name']);
        echo "[info] {$targetDate} の処理を新規開始 zone_id={$zoneId}\n";
        $state = initialize_state($targetDate, $zoneId, $config);
    } else {
        $queueCount = count($state['queue']);
        echo "[info] {$targetDate} の処理を再開 (queue残り{$queueCount}件)\n";
    }

    $state['status'] = 'running';
    save_state_atomic($state, $config);

    $token = $config['cf_api_token'];
    $zoneId = $state['zone_id'];
    $fullPartsDir = $state['output']['full_parts_dir'];
    $filteredPartsDir = $state['output']['filtered_parts_dir'];

    $deadline = microtime(true) + $config['max_runtime_seconds'] - $config['safety_margin_seconds'];
    $runQueries = 0;

    while (!empty($state['queue'])) {
        if (microtime(true) >= $deadline) {
            echo "[info] 実行時間の上限に到達。次回cronで再開\n";
            break;
        }
        if ($runQueries >= $config['max_queries_per_run']) {
            echo "[info] 1回あたりのAPIクエリ上限に到達。次回cronで再開\n";
            break;
        }

        $window = $state['queue'][0];
        $startDt = parse_iso($window['start']);
        $endDt = parse_iso($window['end']);

        if (microtime(true) >= $deadline) {
            break;
        }

        try {
            $rows = query_window($token, $zoneId, $startDt, $endDt);
        } catch (RetryableAPIError $e) {
            $state['totals']['api_errors']++;
            $state['runtime']['last_error'] = $e->getMessage();
            echo "[warn] {$e->getMessage()}\n";
            break;
        }

        $state['totals']['queries']++;
        $runQueries++;
        usleep((int)(QUERY_PACING_SECONDS * 1_000_000));

        if (count($rows) >= ROW_LIMIT) {
            $nextMinutes = next_split_minutes($window['window_minutes']);
            if ($nextMinutes !== null) {
                $subWindows = split_window($window, $nextMinutes);
                array_splice($state['queue'], 0, 1, $subWindows);
                $state['totals']['split_count']++;
                save_state_atomic($state, $config);
                continue;
            } else {
                $state['warnings'][] = "{$window['start']}〜{$window['end']} は最小分割(1分)でも" . ROW_LIMIT . "行に到達。取りこぼしの可能性あり";
            }
        }

        $normalized = array_map('normalize_row', $rows);
        usort($normalized, fn($a, $b) => [$a['datetimeJST'], $a['clientIP'], $a['path']] <=> [$b['datetimeJST'], $b['clientIP'], $b['path']]);
        $filtered = array_values(array_filter($normalized, fn($r) => !is_static_asset($r['path'])));

        // part名はwindow開始時刻から決まるので、書き込み後に落ちて同じwindowを
        // 再取得しても上書きになるだけで済む（重複行が増えない）
        $partName = part_filename($window);
        write_part_atomic("{$fullPartsDir}/{$partName}", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $normalized));
        write_part_atomic("{$filteredPartsDir}/{$partName}", array_map('format_log_line', $filtered));

        $state['totals']['raw_rows'] += count($normalized);
        $state['totals']['filtered_rows'] += count($filtered);
        $state['completed_windows'][] = ['start' => $window['start'], 'end' => $window['end'], 'rows' => count($normalized)];
        array_shift($state['queue']);
        $state['runtime']['last_success_at'] = now_iso();
        save_state_atomic($state, $config);
    }

    if (empty($state['queue'])) {
        finalize_outputs($state);
        $state['status'] = 'completed';
        $state['last_completed_date'] = $state['target_date'];
        $rawRows = $state['totals']['raw_rows'];
        $filteredRows = $state['totals']['filtered_rows'];
        $queries = $state['totals']['queries'];
        echo "[done] {$state['target_date']} の処理が完了。行数={$rawRows}(filtered={$filteredRows}) APIクエリ数={$queries}\n";
        if (!empty($state['warnings'])) {
            $warnCount = count($state['warnings']);
            fwrite(STDERR, "[warn] 警告{$warnCount}件あり\n");
        }
        cleanup_old_logs($config);
    } else {
        $state['status'] = 'partial';
        $queueCount = count($state['queue']);
        $queries = $state['totals']['queries'];
        echo "[info] {$state['target_date']} は未完了 (queue残り{$queueCount}件、今回処理={$queries}クエリ)\n";
    }

    save_state_atomic($state, $config);
}

function main(): void {
    $config = load_config(SCRIPT_DIR . '/config.env');
    @mkdir($config['output_dir'], 0755, true);
    run_once($config);
}

if (!getenv('CF_ACCESS_LOG_GA_FREE_NO_MAIN')) {
    main();
}
