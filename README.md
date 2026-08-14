# cf-access-log-ga-free

Cloudflare GraphQL Analytics API（`httpRequestsAdaptiveGroups`）を使って、日次の集計アクセスログを生成するツールです。

**Cloudflare Free プランでも使えます。** Logpush/Logpull（生ログ配信）はEnterpriseプラン限定ですが、GraphQL Analytics APIはFree/Pro/Businessプランでも利用できます。オリジンサーバー側の設定変更・コード改修は不要です。

共用レンタルサーバー（ロリポップ等）のcron実行時間制限（一般的に約5分）に収まるよう、チェックポイント方式で動作します。1回の実行が時間切れ間近になったら`state.json`に進捗を保存していったん終了し、次回のcron実行で続きから再開します。高頻度（10分ごと等）にcron登録しておけば、1日分が終わっている日は毎回ほぼ何もせず即終了するだけなので、負荷は問題になりません。

## できないこと（重要）

- **完全な生ログの再現はできません**。高トラフィックなゾーンではCloudflare側のAdaptive Samplingがかかり、サンプリングされたリクエストは1行として復元できません（`summary.json`の`sample_interval`で目安を確認できます）
- Freeプランではリファラー・クエリ文字列・コンテンツタイプはAPI権限上取得できません（有料プランなら取得できる可能性があります）

「日次の集計・傾向ログ」であり、Apache/nginxの生アクセスログの完全な代替ではありません。

## セットアップ

1. このディレクトリ一式をサーバーに設置します。出力にはアクセス元IP・User-Agent等が含まれるため、**Webから直接アクセスできない場所**（`public_html`等の公開ディレクトリの外）に置いてください
2. `config.env.example`を`config.env`にコピーし、値を設定します
   ```bash
   cp config.env.example config.env
   chmod 600 config.env
   ```
3. `CF_API_TOKEN`にCloudflare APIトークンを設定します。**Zone > Analytics > Read権限のみ**のカスタムトークンを推奨します（Global API Keyは使わないでください）
4. `CF_ZONE_NAME`に対象ドメイン（例: `example.com`）を設定します
5. `run.sh`に実行権限を付与します
   ```bash
   chmod 755 run.sh
   ```
6. cronに登録します（10分ごとの例）
   ```
   */10 * * * * /path/to/cf-access-log-ga-free/run.sh >> /path/to/cf-access-log-ga-free/run.log 2>&1
   ```

## 動作要件

- bash（`run.sh`の実行）
- python3（標準ライブラリのみ使用。追加パッケージのインストール不要）
- curl等は不要（Pythonの`urllib`でAPIにアクセスします）

## 設定項目（config.env）

| 項目 | 内容 |
|---|---|
| `CF_API_TOKEN` | CloudflareのAPIトークン（Zone > Analytics > Read権限） |
| `CF_ZONE_NAME` | 対象ゾーン名 |
| `SITE_NAME` | 出力ファイル名に使う識別子（省略時は`CF_ZONE_NAME`） |
| `RETENTION_DAYS` | ログの保存日数。これより古い`data/daily/`配下は自動削除（既定90日） |
| `TARGET_DAYS_AGO` | 何日前を処理対象にするか（既定1=前日） |
| `OUTPUT_DIR` | 出力先ディレクトリ |
| `MAX_RUNTIME_SECONDS` | 1回のcron実行での最大処理時間（既定240秒） |
| `MAX_QUERIES_PER_RUN` | 1回のcron実行での最大APIクエリ数 |

## 出力

```
<OUTPUT_DIR>/daily/<date>/
  <site>-aggregated-access-<date>.full.jsonl.gz      全件・正本（分析用）
  <site>-aggregated-access-<date>.filtered.log.gz    静的アセット除外済み（人間可読・日常閲覧用）
  <site>-aggregated-access-<date>.summary.json       実行結果メタデータ（行数・APIクエリ数・警告等）
```

`filtered.log.gz`はjs/css/画像/フォント等の静的アセットを拡張子ベースで除外しています。PDFはパンフレット等ダウンロードのコンバージョン価値を考慮し、除外対象に含めていません。

## トラブルシューティング

- **`CF_API_TOKEN が...`のエラー**: `config.env`にトークンが設定されているか確認してください
- **`APIトークンが無効か、権限が不足しています`**: トークンにZone Analytics Read権限があるか、対象ゾーンへのアクセス権限があるか確認してください
- **`ゾーン '...' が見つかりません`**: `CF_ZONE_NAME`のスペルを確認してください
- **`state.jsonが壊れています`**: 表示されたパスのファイルを退避（リネーム）して再実行してください。その日の処理は最初からやり直しになります
- **前回の実行がまだ動作中のためスキップします、が出続ける**: `run.sh`内の`.run.lock`または`.run.lock.d`が残っていないか確認してください（`LOCK_STALE_SECONDS`経過で自動的に破棄されます）
