# cf-access-log-ga-free

Cloudflare GraphQL Analytics API（`httpRequestsAdaptiveGroups`）を使って、日次の集計アクセスログを生成するツールです。

**Cloudflare Free プランでも使えます。** Logpush/Logpull（生ログ配信）はEnterpriseプラン限定ですが、GraphQL Analytics APIはFree/Pro/Businessプランでも利用できます。オリジンサーバー側の設定変更・コード改修は不要です。

共用レンタルサーバー（ロリポップ等）のcron実行時間制限（一般的に約5分）に収まるよう、チェックポイント方式で動作します。1回の実行が時間切れ間近になったら進捗を保存していったん終了し、次回のcron実行で続きから再開します。高頻度（10分ごと等）にcron登録しておけば、1日分が終わっている日は毎回ほぼ何もせず即終了するだけなので、負荷は問題になりません。

## 実装を選ぶ

サーバーで使える実行環境に応じて、どちらか動く方を選んでください。出力仕様・設定項目・動作方針は共通です。

| 実装 | 動作要件 | ディレクトリ |
|---|---|---|
| Python版 | bash + python3（標準ライブラリのみ） | [`python/`](python/) |
| PHP版 | bash + PHP CLI 7.4以上（`curl`・`zlib`・`json`拡張） | [`php/`](php/) |

python3が使えない環境（PHP CLIしかない共用サーバー等）ではPHP版を、python3が使える環境ではPython版を選んでください。どちらも同じ仕様で動作します。

## できないこと（重要）

- **完全な生ログの再現はできません**。高トラフィックなゾーンではCloudflare側のAdaptive Samplingがかかり、サンプリングされたリクエストは1行として復元できません（`summary.json`の`sample_interval`で目安を確認できます）
- Freeプランではリファラー・クエリ文字列・コンテンツタイプはAPI権限上取得できません（有料プランなら取得できる可能性があります）

「日次の集計・傾向ログ」であり、Apache/nginxの生アクセスログの完全な代替ではありません。

## 出力

```
<OUTPUT_DIR>/daily/<date>/
  <site>-aggregated-access-<date>.full.jsonl.gz      全件・正本（分析用）
  <site>-aggregated-access-<date>.filtered.log.gz    静的アセット除外済み（人間可読・日常閲覧用）
  <site>-aggregated-access-<date>.summary.json       実行結果メタデータ（行数・APIクエリ数・警告等）
```

`filtered.log.gz`はjs/css/画像/フォント等の静的アセットを拡張子ベースで除外しています。PDFはパンフレット等ダウンロードのコンバージョン価値を考慮し、除外対象に含めていません。

## 設定項目（config.env）

実装（python/php）共通で、以下の項目を設定します。詳しいセットアップ手順は各実装ディレクトリのREADMEを参照してください。

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
