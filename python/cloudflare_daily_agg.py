#!/usr/bin/env python3
"""
Cloudflare GraphQL Analytics API (httpRequestsAdaptiveGroups) から
日次の集計アクセスログを生成する。

共用レンタルサーバーのcron実行時間制限（一般的に約5分）に収まるよう、
チェックポイント方式で動作する。1回の実行が MAX_RUNTIME_SECONDS に近づいたら
state.json に進捗を保存していったん終了し、次回のcron実行で続きから再開する。

想定運用: run.sh から10分ごと等の高頻度cronで起動する。前日分の処理が
完了していれば何もせず即終了するので、高頻度に呼んでも負荷は小さい。

出力:
  <OUTPUT_DIR>/daily/<date>/<site>-aggregated-access-<date>.full.jsonl.gz     全件・正本
  <OUTPUT_DIR>/daily/<date>/<site>-aggregated-access-<date>.filtered.log.gz   静的アセット除外済み
  <OUTPUT_DIR>/daily/<date>/<site>-aggregated-access-<date>.summary.json      実行結果メタデータ
"""
import gzip
import json
import shutil
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

JST = ZoneInfo("Asia/Tokyo")
UTC = ZoneInfo("UTC")

SCRIPT_DIR = Path(__file__).resolve().parent
API_BASE = "https://api.cloudflare.com/client/v4"
GRAPHQL_URL = f"{API_BASE}/graphql"

ROW_LIMIT = 10000
SPLIT_STEPS_MINUTES = [60, 30, 15, 5, 1]
QUERY_PACING_SECONDS = 0.3
STATE_SCHEMA_VERSION = 1

# filtered.log から除外する静的アセットの拡張子。
# PDFはパンフレット等ダウンロードのコンバージョン価値があるため除外対象に含めない。
STATIC_ASSET_EXTENSIONS = {
    "js", "css", "png", "jpg", "jpeg", "gif", "svg", "webp", "ico",
    "woff", "woff2", "ttf", "eot", "map", "mp4",
}

DIMENSIONS_QUERY = """
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
"""


class RetryableAPIError(Exception):
    pass


def load_config(path: Path) -> dict:
    if not path.exists():
        sys.exit(f"[error] 設定ファイルが見つかりません: {path}\n"
                  f"        config.env.example を config.env にコピーして値を設定してください")
    raw = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        raw[key.strip()] = value.strip().strip('"').strip("'")

    def require(key: str) -> str:
        v = raw.get(key, "")
        if not v:
            sys.exit(f"[error] 設定ファイルに {key} が設定されていません")
        return v

    def positive_int(key: str, default: int) -> int:
        v = raw.get(key, "")
        if not v:
            return default
        try:
            n = int(v)
        except ValueError:
            sys.exit(f"[error] 設定 {key} は整数で指定してください（現在の値: {v!r}）")
        if n < 1:
            sys.exit(f"[error] 設定 {key} は1以上にしてください（現在の値: {v!r}）")
        return n

    zone_name = require("CF_ZONE_NAME")
    max_runtime_seconds = positive_int("MAX_RUNTIME_SECONDS", 240)
    safety_margin_seconds = 30
    if max_runtime_seconds <= safety_margin_seconds:
        sys.exit(f"[error] MAX_RUNTIME_SECONDS は{safety_margin_seconds}より大きくしてください"
                  f"（現在の値: {max_runtime_seconds}）")

    config = {
        "cf_api_token": require("CF_API_TOKEN"),
        "cf_zone_name": zone_name,
        "site_name": raw.get("SITE_NAME") or zone_name,
        "retention_days": positive_int("RETENTION_DAYS", 90),
        "target_days_ago": positive_int("TARGET_DAYS_AGO", 1),
        "output_dir": (SCRIPT_DIR / (raw.get("OUTPUT_DIR") or "./data")).resolve(),
        "max_runtime_seconds": max_runtime_seconds,
        "max_queries_per_run": positive_int("MAX_QUERIES_PER_RUN", 200),
        "safety_margin_seconds": safety_margin_seconds,
    }
    return config


def now_iso() -> str:
    return datetime.now(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso(s: str) -> datetime:
    return datetime.strptime(s, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=UTC)


def state_path_for(config: dict) -> Path:
    return config["output_dir"] / "state.json"


def load_state(config: dict) -> dict | None:
    path = state_path_for(config)
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        sys.exit(
            f"[error] {path} が壊れています（JSONとして読めません）。\n"
            f"        このファイルを退避（リネーム等）してから再実行してください。"
            f"1日分の処理は最初からやり直しになります。"
        )


def save_state_atomic(state: dict, config: dict) -> None:
    path = state_path_for(config)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = path.with_suffix(path.suffix + ".tmp")
    state["runtime"]["updated_at"] = now_iso()
    tmp_path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp_path.replace(path)


def api_request(url: str, token: str, payload: dict) -> dict:
    body = json.dumps(payload).encode()
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    last_err = None
    for attempt in range(1, 3):
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return json.load(resp)
        except urllib.error.HTTPError as e:
            body_text = e.read().decode(errors="replace")
            if e.code == 429 or 500 <= e.code < 600:
                last_err = f"HTTP {e.code}: {body_text}"
                time.sleep(3)
                continue
            raise RuntimeError(f"HTTP {e.code}: {body_text}") from e
        except urllib.error.URLError as e:
            last_err = str(e)
            time.sleep(3)
            continue
    raise RetryableAPIError(f"APIリクエストが失敗（次回cronで再試行）: {last_err}")


def resolve_zone_id(token: str, zone_name: str) -> str:
    req = urllib.request.Request(
        f"{API_BASE}/zones?name={zone_name}",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.load(resp)
    except urllib.error.HTTPError as e:
        body_text = e.read().decode(errors="replace")
        if e.code in (401, 403):
            sys.exit(
                f"[error] Cloudflare APIトークンが無効か、権限が不足しています(HTTP {e.code})。\n"
                f"        config.env の CF_API_TOKEN と、トークンの Zone Read 権限を確認してください。\n"
                f"        詳細: {body_text}"
            )
        sys.exit(f"[error] Cloudflare APIへの接続に失敗しました(HTTP {e.code}): {body_text}")
    except urllib.error.URLError as e:
        sys.exit(f"[error] Cloudflare APIへ接続できません（ネットワークを確認してください）: {e}")

    result = data.get("result") or []
    if not result:
        sys.exit(
            f"[error] ゾーン '{zone_name}' がこのAPIトークンから見つかりません。\n"
            f"        config.env の CF_ZONE_NAME が正しいか、トークンにこのゾーンへのアクセス権限があるか確認してください。"
        )
    return result[0]["id"]


def query_window(token: str, zone_id: str, start: datetime, end: datetime) -> list:
    payload = {
        "query": DIMENSIONS_QUERY,
        "variables": {
            "zoneTag": zone_id,
            "start": start.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "end": end.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "limit": ROW_LIMIT,
        },
    }
    result = api_request(GRAPHQL_URL, token, payload)
    if result.get("errors"):
        messages = " / ".join(err.get("message", str(err)) for err in result["errors"])
        if "access" in messages.lower() or "permission" in messages.lower() or "not authorized" in messages.lower():
            sys.exit(
                f"[error] Cloudflare APIトークンに、このデータを取得する権限がありません。\n"
                f"        トークンに Zone > Analytics > Read 権限が付与されているか確認してください。\n"
                f"        詳細: {messages}"
            )
        raise RuntimeError(f"GraphQLエラー: {messages}")
    return result["data"]["viewer"]["zones"][0]["httpRequestsAdaptiveGroups"]


def normalize_row(row: dict) -> dict:
    d = row["dimensions"]
    dt_utc = datetime.strptime(d["datetimeMinute"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=UTC)
    dt_jst = dt_utc.astimezone(JST)
    return {
        "count": row["count"],
        "sampleInterval": row["avg"]["sampleInterval"],
        "edgeResponseBytes": row["sum"]["edgeResponseBytes"],
        "datetimeUTC": d["datetimeMinute"],
        "datetimeJST": dt_jst.strftime("%Y-%m-%dT%H:%M:%S+09:00"),
        "clientIP": d["clientIP"],
        "country": d["clientCountryName"],
        "method": d["clientRequestHTTPMethodName"],
        "path": d["clientRequestPath"],
        "protocol": d["clientRequestHTTPProtocol"],
        "status": d["edgeResponseStatus"],
        "userAgent": d["userAgent"],
    }


def is_static_asset(path: str) -> bool:
    path = path.split("?", 1)[0].split("#", 1)[0]
    ext = path.rsplit(".", 1)[-1].lower() if "." in path.rsplit("/", 1)[-1] else ""
    return ext in STATIC_ASSET_EXTENSIONS


def format_log_line(r: dict) -> str:
    dt = datetime.strptime(r["datetimeJST"], "%Y-%m-%dT%H:%M:%S+09:00")
    ts = dt.strftime("%d/%b/%Y:%H:%M:%S +0900")
    return (
        f'count={r["count"]} sample_interval={r["sampleInterval"]} '
        f'bytes={r["edgeResponseBytes"]} {r["clientIP"]} - {r["country"]} '
        f'[{ts}] "{r["method"]} {r["path"]} {r["protocol"]}" {r["status"]} '
        f'"{r["userAgent"]}"'
    )


def part_filename(window: dict) -> str:
    # windowの開始時刻から決まる名前にすることで、同じwindowを再処理しても
    # 上書きになり重複が起きない（cronがkillされた場合の再実行に対して冪等）
    return window["start"].replace(":", "") + ".gz"


def write_part_atomic(path: Path, lines: list) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = path.with_suffix(path.suffix + ".tmp")
    with gzip.open(tmp_path, "wt", encoding="utf-8") as f:
        for line in lines:
            f.write(line + "\n")
    tmp_path.replace(path)


def concat_parts(parts_dir: Path, dest: Path) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = dest.with_suffix(dest.suffix + ".tmp")
    with tmp_path.open("wb") as out:
        if parts_dir.exists():
            for part in sorted(parts_dir.glob("*.gz")):
                out.write(part.read_bytes())
    tmp_path.replace(dest)


def jst_date_str(dt: datetime) -> str:
    return dt.astimezone(JST).strftime("%Y-%m-%d")


def day_bounds_utc(date_str: str) -> tuple[datetime, datetime]:
    start_jst = datetime.strptime(date_str, "%Y-%m-%d").replace(tzinfo=JST)
    end_jst = start_jst + timedelta(days=1)
    return start_jst.astimezone(UTC), end_jst.astimezone(UTC)


def build_initial_queue(day_start: datetime, day_end: datetime) -> list:
    queue = []
    cursor = day_start
    while cursor < day_end:
        window_end = min(cursor + timedelta(minutes=60), day_end)
        queue.append({
            "start": cursor.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "end": window_end.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "window_minutes": 60,
            "attempts": 0,
        })
        cursor = window_end
    return queue


def next_split_minutes(current_minutes: int) -> int | None:
    if current_minutes not in SPLIT_STEPS_MINUTES:
        return None
    idx = SPLIT_STEPS_MINUTES.index(current_minutes)
    if idx + 1 >= len(SPLIT_STEPS_MINUTES):
        return None
    return SPLIT_STEPS_MINUTES[idx + 1]


def split_window(window: dict, minutes: int) -> list:
    start = parse_iso(window["start"])
    end = parse_iso(window["end"])
    sub = []
    cursor = start
    while cursor < end:
        sub_end = min(cursor + timedelta(minutes=minutes), end)
        sub.append({
            "start": cursor.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "end": sub_end.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "window_minutes": minutes,
            "attempts": 0,
        })
        cursor = sub_end
    return sub


def initialize_state(target_date: str, zone_id: str, config: dict) -> dict:
    day_start, day_end = day_bounds_utc(target_date)
    work_dir = config["output_dir"] / "work" / target_date
    if work_dir.exists():
        shutil.rmtree(work_dir)
    work_dir.mkdir(parents=True, exist_ok=True)
    final_dir = config["output_dir"] / "daily" / target_date

    return {
        "schema_version": STATE_SCHEMA_VERSION,
        "status": "running",
        "site_name": config["site_name"],
        "zone_id": zone_id,
        "target_date": target_date,
        "day_start": day_start.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "day_end": day_end.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "queue": build_initial_queue(day_start, day_end),
        "completed_windows": [],
        "totals": {
            "queries": 0,
            "raw_rows": 0,
            "filtered_rows": 0,
            "api_errors": 0,
            "split_count": 0,
        },
        "warnings": [],
        "output": {
            "work_dir": str(work_dir),
            "full_parts_dir": str(work_dir / "parts" / "full"),
            "filtered_parts_dir": str(work_dir / "parts" / "filtered"),
            "final_dir": str(final_dir),
        },
        "runtime": {
            "started_at": now_iso(),
            "updated_at": now_iso(),
            "last_success_at": None,
            "last_error": None,
        },
        "last_completed_date": None,
    }


def determine_target_date(state: dict | None, config: dict) -> str | None:
    boundary = (datetime.now(JST) - timedelta(days=config["target_days_ago"])).strftime("%Y-%m-%d")
    if state is None:
        return boundary
    if state["status"] in ("running", "partial"):
        return state["target_date"]
    last_completed = state.get("last_completed_date")
    if last_completed is None:
        return boundary
    next_date = (datetime.strptime(last_completed, "%Y-%m-%d") + timedelta(days=1)).strftime("%Y-%m-%d")
    if next_date <= boundary:
        return next_date
    return None


def finalize_outputs(state: dict) -> None:
    final_dir = Path(state["output"]["final_dir"])
    final_dir.mkdir(parents=True, exist_ok=True)
    site = state["site_name"]
    date_str = state["target_date"]

    full_final = final_dir / f"{site}-aggregated-access-{date_str}.full.jsonl.gz"
    filtered_final = final_dir / f"{site}-aggregated-access-{date_str}.filtered.log.gz"
    summary_final = final_dir / f"{site}-aggregated-access-{date_str}.summary.json"

    concat_parts(Path(state["output"]["full_parts_dir"]), full_final)
    concat_parts(Path(state["output"]["filtered_parts_dir"]), filtered_final)

    # part再書き込み(クラッシュ後の再実行)があってもtotalsの累積値はズレうるため、
    # 最終ファイルの実際の行数を数え直して正本とする
    with gzip.open(full_final, "rt", encoding="utf-8") as f:
        aggregated_rows = sum(1 for _ in f)
    with gzip.open(filtered_final, "rt", encoding="utf-8") as f:
        filtered_rows = sum(1 for _ in f)

    summary = {
        "site_name": site,
        "zone_id": state["zone_id"],
        "date_jst": date_str,
        "period_start_utc": state["day_start"],
        "period_end_utc": state["day_end"],
        "aggregated_rows": aggregated_rows,
        "filtered_rows": filtered_rows,
        "api_query_count": state["totals"]["queries"],
        "split_count": state["totals"]["split_count"],
        "warnings": state["warnings"],
        "generated_at": now_iso(),
    }
    summary_tmp = summary_final.with_suffix(summary_final.suffix + ".tmp")
    summary_tmp.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    summary_tmp.replace(summary_final)

    work_dir = Path(state["output"]["work_dir"])
    if work_dir.exists():
        shutil.rmtree(work_dir, ignore_errors=True)


def cleanup_old_logs(config: dict) -> None:
    daily_dir = config["output_dir"] / "daily"
    if not daily_dir.exists():
        return
    cutoff = (datetime.now(JST) - timedelta(days=config["retention_days"])).strftime("%Y-%m-%d")
    for entry in daily_dir.iterdir():
        if entry.is_dir() and entry.name < cutoff:
            shutil.rmtree(entry, ignore_errors=True)
            print(f"[info] 保存期間({config['retention_days']}日)超過のため削除: {entry}")


def run_once(config: dict) -> None:
    state = load_state(config)
    target_date = determine_target_date(state, config)
    if target_date is None:
        print("[info] 処理対象の新しい日付なし。終了")
        return

    if state is None or state["status"] == "completed" or state.get("target_date") != target_date:
        token = config["cf_api_token"]
        zone_id = resolve_zone_id(token, config["cf_zone_name"])
        print(f"[info] {target_date} の処理を新規開始 zone_id={zone_id}")
        state = initialize_state(target_date, zone_id, config)
    else:
        print(f"[info] {target_date} の処理を再開 (queue残り{len(state['queue'])}件)")

    state["status"] = "running"
    save_state_atomic(state, config)

    token = config["cf_api_token"]
    zone_id = state["zone_id"]
    full_parts_dir = Path(state["output"]["full_parts_dir"])
    filtered_parts_dir = Path(state["output"]["filtered_parts_dir"])

    deadline = time.monotonic() + config["max_runtime_seconds"] - config["safety_margin_seconds"]
    run_queries = 0

    while state["queue"]:
        if time.monotonic() >= deadline:
            print("[info] 実行時間の上限に到達。次回cronで再開")
            break
        if run_queries >= config["max_queries_per_run"]:
            print("[info] 1回あたりのAPIクエリ上限に到達。次回cronで再開")
            break

        window = state["queue"][0]
        start_dt = parse_iso(window["start"])
        end_dt = parse_iso(window["end"])

        if time.monotonic() >= deadline:
            break

        try:
            rows = query_window(token, zone_id, start_dt, end_dt)
        except RetryableAPIError as e:
            state["totals"]["api_errors"] += 1
            state["runtime"]["last_error"] = str(e)
            print(f"[warn] {e}")
            break

        state["totals"]["queries"] += 1
        run_queries += 1
        time.sleep(QUERY_PACING_SECONDS)

        if len(rows) >= ROW_LIMIT:
            next_minutes = next_split_minutes(window["window_minutes"])
            if next_minutes is not None:
                sub_windows = split_window(window, next_minutes)
                state["queue"] = sub_windows + state["queue"][1:]
                state["totals"]["split_count"] += 1
                save_state_atomic(state, config)
                continue
            else:
                state["warnings"].append(
                    f"{window['start']}〜{window['end']} は最小分割(1分)でも{ROW_LIMIT}行に到達。取りこぼしの可能性あり"
                )

        normalized = [normalize_row(r) for r in rows]
        normalized.sort(key=lambda r: (r["datetimeJST"], r["clientIP"], r["path"]))
        filtered = [r for r in normalized if not is_static_asset(r["path"])]

        # part名はwindow開始時刻から決まるので、書き込み後に落ちて同じwindowを
        # 再取得しても上書きになるだけで済む（重複行が増えない）
        part_name = part_filename(window)
        write_part_atomic(full_parts_dir / part_name, [json.dumps(r, ensure_ascii=False) for r in normalized])
        write_part_atomic(filtered_parts_dir / part_name, [format_log_line(r) for r in filtered])

        state["totals"]["raw_rows"] += len(normalized)
        state["totals"]["filtered_rows"] += len(filtered)
        state["completed_windows"].append({
            "start": window["start"], "end": window["end"], "rows": len(normalized),
        })
        state["queue"].pop(0)
        state["runtime"]["last_success_at"] = now_iso()
        save_state_atomic(state, config)

    if not state["queue"]:
        finalize_outputs(state)
        state["status"] = "completed"
        state["last_completed_date"] = state["target_date"]
        print(f"[done] {state['target_date']} の処理が完了。"
              f"行数={state['totals']['raw_rows']}(filtered={state['totals']['filtered_rows']}) "
              f"APIクエリ数={state['totals']['queries']}")
        if state["warnings"]:
            print(f"[warn] 警告{len(state['warnings'])}件あり", file=sys.stderr)
        cleanup_old_logs(config)
    else:
        state["status"] = "partial"
        print(f"[info] {state['target_date']} は未完了 (queue残り{len(state['queue'])}件、"
              f"今回処理={state['totals']['queries']}クエリ)")

    save_state_atomic(state, config)


def main():
    config = load_config(SCRIPT_DIR / "config.env")
    config["output_dir"].mkdir(parents=True, exist_ok=True)
    run_once(config)


if __name__ == "__main__":
    main()
