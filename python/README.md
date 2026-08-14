# cf-access-log-ga-free (Python版)

python3のみで動作する実装です。追加パッケージのインストールは不要です（標準ライブラリのみ使用）。

全体の説明・できないこと・出力仕様は[リポジトリ全体のREADME](../README.md)を参照してください。ここではPython版固有のセットアップ手順のみ説明します。

## セットアップ

1. `python`ディレクトリ一式をサーバーに設置します。出力にはアクセス元IP・User-Agent等が含まれるため、**Webから直接アクセスできない場所**（`public_html`等の公開ディレクトリの外）に置いてください
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
   */10 * * * * /path/to/cf-access-log-ga-free/python/run.sh >> /path/to/cf-access-log-ga-free/python/run.log 2>&1
   ```

## 動作要件

- bash（`run.sh`の実行）
- python3（標準ライブラリのみ使用。追加パッケージのインストール不要）

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

## トラブルシューティング

- **`CF_API_TOKEN が...`のエラー**: `config.env`にトークンが設定されているか確認してください
- **`APIトークンが無効か、権限が不足しています`**: トークンにZone Analytics Read権限があるか、対象ゾーンへのアクセス権限があるか確認してください
- **`ゾーン '...' が見つかりません`**: `CF_ZONE_NAME`のスペルを確認してください
- **`state.jsonが壊れています`**: 表示されたパスのファイルを退避（リネーム）して再実行してください。その日の処理は最初からやり直しになります
- **前回の実行がまだ動作中のためスキップします、が出続ける**: `run.sh`内の`.run.lock`または`.run.lock.d`が残っていないか確認してください（`LOCK_STALE_SECONDS`経過で自動的に破棄されます）
