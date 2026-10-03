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
- **Cloudflare側のGraphQL Analytics APIには、実行時点から遡って取得できる期間の上限があります**（実測ではFreeプランのゾーンで約1週間+1日。上限を超える期間を要求すると`cannot request data older than ...`というエラーになります）。この上限より古いデータは、Cloudflare側にも二度と取得手段がありません

「日次の集計・傾向ログ」であり、Apache/nginxの生アクセスログの完全な代替ではありません。

## 集計条件とサンプリング：1行とcountの意味

Python版・PHP版とも、集計とサンプリングは別の処理です。**出力の1行は1リクエストではなく、次の8項目がすべて同じアクセスの集計結果です。**

| 集計項目 | APIのフィールド | 同じ行にまとめる条件 |
|---|---|---|
| 時刻 | `datetimeMinute` | 同じ年月日・時・分（秒は区別しない） |
| アクセス元IP | `clientIP` | 同じIPアドレス |
| メソッド | `clientRequestHTTPMethodName` | 同じメソッド（GET、POSTなど） |
| パス | `clientRequestPath` | 同じパス（`/about`など） |
| HTTPバージョン | `clientRequestHTTPProtocol` | 同じHTTPバージョン |
| 応答ステータス | `edgeResponseStatus` | 同じステータスコード |
| User-Agent | `userAgent` | 同じUser-Agent文字列 |
| 国 | `clientCountryName` | 同じ国コード |

例えば、同じIPから同じ分に来た `GET /about` と `GET /service` は、パスが違うため別の行になります。8項目がすべて同じ2件なら一つの行にまとまり、サンプリングがなければ `count=2` になります。同じIPでも、同じ人・端末からのアクセスとは限りません。

対象は `requestSource: "eyeball"` で絞っています。これはブラウザーの正常なアクセスだけを保証する条件ではありません。また、この実装はホスト名・クエリ文字列・リファラーを集計項目に含めていません。パスが同じでも、ホスト名や `?` 以降が違うアクセスを、この出力だけでは区別できません。

### countは実ログの件数とは限らない

`count` はCloudflareが返した値をそのまま保存しています。本ツールが他の項目から推測して作った値ではありません。サンプリングが適用された場合、Cloudflareは抽出したデータから全体の件数を推定し、補正済みの値を `count` として返します。

例えば、6分の1の確率で抽出された1件には、集計時に6件分の重みが付きます。これは「実在する6件を確認して1件にまとめた」という意味ではありません。特定のIP・パス・分まで細かく絞ると、実ログが1件でも `count=6` になることがあり、`count` と実ログの件数が一致するとは限りません。`count=6` の行から、元の6件を復元することもできません。

各行の `sampleInterval`（テキスト出力では `sample_interval`）には、APIの `avg.sampleInterval` を保存しています。これはその集計グループのサンプリング間隔の平均値です。1は間隔による補正がないことを示し、1より大きい値はサンプリングを示します。**`count` は補正済みなので、さらに `sampleInterval` を掛けてはいけません。**

### なぜsampleIntervalが1・3・6などに分かれるのか

Cloudflareは分析の処理量を抑えるため、データ量や内部の処理状況に応じて抽出率を調整します。抽出率は時間や処理ノードによって変わり、複数の処理段階で追加の間引きが行われる場合もあります。そのため、同じ分のアクセスでも異なる重みが付き、集計行ごとに `sampleInterval` が違うことがあります。

確認した公式資料では、この仕組みは説明されていますが、「この条件なら3、この条件なら6」という具体的な閾値や、個々のアクセスで倍率が決まった理由までは確認できません。IP・国・メソッド・パスなどは上記の集計条件であり、特定のページだから倍率6になる、という条件を本ツールが設定しているわけではありません。本ツールからCloudflare側の抽出率を指定することもできません。

仕様の根拠： [Cloudflare GraphQL APIのサンプリング](https://developers.cloudflare.com/analytics/graphql-api/sampling/)、[Cloudflareのサンプリング処理と補正方法の技術解説](https://blog.cloudflare.com/how-we-make-sense-of-too-much-data/)。

## 運用上の重要な注意：cronの停止は取り返しがつかない

このツールは「上記のAPI取得期間の上限に収まる頻度で確実に実行され続けること」を前提に設計されています。**cronが上限日数を超えて停止すると、その間のアクセスログはCloudflare側にも本ツール側にも残らず、恒久的に失われます**（後から気づいても取得しようがありません）。

- サーバー障害・`state.json`の破損・APIトークンの失効/権限変更・アカウント停止等で長期間cronが動かなくなっていないか、定期的に`summary.json`の生成日時を確認することを推奨します
- 可能であれば、外形監視（cronヘルスチェック通知サービス等）と組み合わせて、実行が止まったこと自体に早期に気づける仕組みを用意してください

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
