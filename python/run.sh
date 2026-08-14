#!/bin/bash
# cf-access-log-ga-free のcron入口。
# 多重起動を防止した上で cf_access_log_ga_free.py を実行する。
#
# cron設定例（10分ごと。crontab -e で登録）:
#   */10 * * * * /path/to/cf-access-log-ga-free/python/run.sh >> /path/to/cf-access-log-ga-free/python/run.log 2>&1
#
# 1日分の処理が完了していれば、この実行はほぼ何もせず即終了する
# （state.jsonを見て「やることがない」と判断してすぐ終わるだけ）ので、
# 高頻度に呼んでもサーバー負荷は小さい。

set -eu

# 出力ログにはIP・User-Agent等が含まれるため、生成ファイルを他ユーザーから
# 読めないようにする（config.envのAPIトークンも同様。別途 chmod 600 推奨）
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
cd "$SCRIPT_DIR"

# 前回実行がロックを持ったまま止まっているとみなすまでの秒数。
# config.env の MAX_RUNTIME_SECONDS より十分大きくすること
# （必要なら環境変数 LOCK_STALE_SECONDS で上書き可能）
LOCK_STALE_SECONDS="${LOCK_STALE_SECONDS:-900}"
PYTHON_BIN="${PYTHON_BIN:-python3}"

run_python() {
    "$PYTHON_BIN" "$SCRIPT_DIR/cf_access_log_ga_free.py"
}

if command -v flock >/dev/null 2>&1; then
    LOCK_FILE="$SCRIPT_DIR/.run.lock"
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "[info] 前回の実行がまだ動作中のためスキップします"
        exit 0
    fi
    run_python
else
    # flockが使えない環境向けのフォールバック。mkdirはOS上で原子的なので
    # ロックとして使える。cronの強制終了等で残留した古いロックは破棄する。
    LOCK_DIR="$SCRIPT_DIR/.run.lock.d"
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        trap 'rm -rf "$LOCK_DIR" 2>/dev/null || true' EXIT INT TERM
        date +%s > "$LOCK_DIR/created_at"
        run_python
    else
        created_at="$(cat "$LOCK_DIR/created_at" 2>/dev/null || echo 0)"
        now="$(date +%s)"
        age=$(( now - created_at ))
        if [ "$age" -gt "$LOCK_STALE_SECONDS" ]; then
            echo "[warn] 古いロック(${age}秒前)を破棄して実行します"
            rm -rf "$LOCK_DIR"
            mkdir "$LOCK_DIR"
            trap 'rm -rf "$LOCK_DIR" 2>/dev/null || true' EXIT INT TERM
            date +%s > "$LOCK_DIR/created_at"
            run_python
        else
            echo "[info] 前回の実行がまだ動作中のためスキップします"
            exit 0
        fi
    fi
fi
