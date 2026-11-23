<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">ClinicalTrials.gov から臨床試験をインポートして検索し、フォロー中の試験が登録・変更されたときや募集を開始したときにメールで通知を受け取れます。</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a> · <a href="README.es.md">Español</a> · <a href="README.zh-CN.md">简体中文</a> · <a href="README.zh-TW.md">繁體中文</a> · <a href="README.ja.md">日本語</a> · <a href="README.ko.md">한국어</a>
</p>

<p align="center">
  <img alt="Status: early development" src="https://img.shields.io/badge/status-early_development-orange?style=flat-square">
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/ci.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/ci.yml?branch=main&label=tests&logo=githubactions&logoColor=white&style=flat-square"></a>
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/packages.yml"><img alt="Packages audit" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/packages.yml?branch=main&label=packages&logo=dependabot&logoColor=white&style=flat-square"></a>
  <img alt="PHP 8.6" src="https://img.shields.io/badge/PHP-8.6-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white">
  <img alt="TypeScript 7" src="https://img.shields.io/badge/TypeScript-7-3178C6?style=flat-square&logo=typescript&logoColor=white">
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Redis 8.10" src="https://img.shields.io/badge/Redis-8.10-FF4438?style=flat-square&logo=redis&logoColor=white">
  <img alt="Stripe" src="https://img.shields.io/badge/billing-Stripe-635BFF?style=flat-square&logo=stripe&logoColor=white">
  <img alt="Auth: JWT" src="https://img.shields.io/badge/auth-JWT-000000?style=flat-square&logo=jsonwebtokens&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/runs%20with-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square">
</p>

<p align="center">
  <a href="#機能">機能</a> ·
  <a href="#技術スタック">技術スタック</a> ·
  <a href="#クイックスタート">クイックスタート</a> ·
  <a href="#api">API</a> ·
  <a href="#仕組み">仕組み</a> ·
  <a href="#開発">開発</a> ·
  <a href="ROADMAP.md">ロードマップ</a> ·
  <a href="CHANGELOG.md">変更履歴</a>
</p>

## 機能

### ClinicalTrials.gov からのインポート

- **最初にレジストリ全体を、以降は変更分のみを取得。** `php artisan clinicaltrials:fetch` は本番の API v2（`https://clinicaltrials.gov/api/v2/studies`、TLS 検証あり）を読み込み、`nextPageToken` をたどってすべてのページを取得し、実行ごとに記録を残します。2回目以降の実行では、前回成功した実行以降に更新された試験だけを要求します。
- **毎日実行。** スケジューラーが 03:00（`CLINICAL_TRIALS_SCHEDULE_TIME`）にインポートをキューに追加し、キューワーカーが一度に1件ずつ実行します。
- **繰り返し実行しても安全。** 試験は NCT 番号で照合されます。各試験とその関連リストは1つのトランザクションで保存され、すべてのリストは空のリストも含めて現在の内容に置き換えられます。失敗した試験はロールバックされてカウントされ、実行はそのまま続行されます。
- **失敗は明示的に扱われます。** レート制限（HTTP 429）、サーバーエラー、接続エラーは、指数バックオフで上限回数まで再試行されます。レジストリを読み込めなかった実行は失敗として記録され、コマンドはエラーで終了します。
- **変更は変更前と変更後の値とともに追跡されます。** 新しい試験は新規として記録され、ベースラインになります。以降のインポートでは、全体の募集状況、実施施設（追加、削除、または施設ごとの状況の変更）、開始日と完了日（元の精度を保持し、月までの日付は月のまま）、結果の公開が比較され、構造化された詳細として保存されます。実施施設のリストは施設名と所在地で比較されるため、レジストリ上の並び順が変わっただけでは変更として扱われません。各変更には安定したフィンガープリントがあるため、再試行や繰り返しのインポートで同じ変更が二重に記録・通知されることはありません。
- **部分的な日付は正規化されます**（`2024` → `2024-01-01`、`2024-06` → `2024-06-01`）。試験のペイロード全体は `raw_json` に保持されます。

### その他のレジストリ

さらに12のレジストリを `php artisan clinicaltrials:fetch --source=<key>` でインポートできます。毎日のスケジュールで実行されるのは ClinicalTrials.gov のみです。

| キー | レジストリ | 読み込み方法 | 差分取得 | 商用利用 |
|---|---|---|---|---|
| `ctis` | CTIS（EU/EEA） | ポータルの JSON API | あり（最終更新） | 可（EMA のクレジット表記） |
| `isrctn` | ISRCTN | XML API | あり（最終編集） | 可（CC BY / CC0） |
| `euctr` | EU Clinical Trials Register | プレーンテキストのエクスポート | 新規試験のみ | 可（レジストリのクレジット表記） |
| `drks` | DRKS（ドイツ） | JSON ダンプ。CC BY 4.0 のレコード（2025年以降に登録または更新）のみ | あり（最終更新） | 可（CC BY 4.0） |
| `reec` | REEC（スペイン） | REST API | 直近90日間の登録 | 可（AEMPS の出典表記） |
| `rebec` | ReBEC（ブラジル） | Web ページ | あり（最終承認） | 未確認 |
| `renis` | RENIS（アルゼンチン） | XLSX ファイル | なし | 可（CC BY 4.0） |
| `health_canada` | Health Canada | JSON API | なし | 可（OGL Canada） |
| `tfda` | TFDA（台湾） | CSV の ZIP | なし | 可（Open Government Data License） |
| `ppb_kenya` | Pharmacy and Poisons Board（ケニア） | Web ページ | あり（承認日） | 未確認 |
| `eda_egypt` | Egyptian Drug Authority | PDF | なし | 未確認 |
| `moz_ukraine` | ウクライナ保健省の命令 | PDF の附属書 | 最新の命令 | 未確認 |

さらに8つのインポーターが実装・テスト済みですが、レジストリが求めるものを運用者が用意するまでは無効のままです。

| キー | レジストリ | 読み込み方法 | 必要なもの | 商用利用 |
|---|---|---|---|---|
| `nci_cts` | NCI Clinical Trials Search API（米国、がん） | JSON API。試験は ClinicalTrials.gov のレコードにリンク | `REGISTRY_NCI_CTS_API_KEY` | 可（NCI のクレジット表記が必要） |
| `mfds` | MFDS 承認済み試験計画（韓国） | data.go.kr API | `REGISTRY_MFDS_SERVICE_KEY` | 可 |
| `cris` | CRIS（韓国） | data.go.kr API | `REGISTRY_CRIS_SERVICE_KEY` | 不可（KOGL 第2類型）。出典を明記して表示し、AI ダイジェストには含めない |
| `anzctr` | ANZCTR（オーストラリア・ニュージーランド） | Web ページ | 書面による許可、`REGISTRY_PERMISSION_ANZCTR` | 不可 |
| `umin` | UMIN-CTR（日本） | 毎日の CSV ダンプ | 書面による許可、`REGISTRY_PERMISSION_UMIN` | 不可 |
| `hra` | HRA の研究概要（英国） | Web ページ | 書面による許可、`REGISTRY_PERMISSION_HRA` | 不可 |
| `sukl` | SÚKL（チェコ） | Web サイトの検索 API | 書面による許可、`REGISTRY_PERMISSION_SUKL` | 不可 |
| `orphanet` | Orphanet の臨床試験 | Orphadata の XML ダンプ | Orphanet-AISBL との契約、`REGISTRY_PERMISSION_ORPHANET` と `REGISTRY_ORPHANET_DUMP_URL` | 不可 |

キーが必要な API と UMIN のマッピングは、キーもサンプルも入手できなかったため、ドキュメントをもとに合成フィクスチャで作成しました。最初の本番実行で確認してください。

- **すべてのインポーターは法務レビューを通過しています。** レビューは各ソースクラスと [docs/registries-evaluation.md](docs/registries-evaluation.md)（第7節）に記録されており、同文書にはまだインポートできないレジストリとそれぞれに必要な事項（WHO ICTRP、UMIN-CTR、CRIS、ANZCTR、HRA など）も記載されています。
- **規約で商用利用が確認できないレジストリのレコードは、出典を明記して無料で表示しますが、AI ダイジェストには含めません。** レジストリが再利用を書面で確認するまでこの扱いです。
- **節度あるアクセス。** リクエストには連絡先アドレスを含む user agent（`REGISTRY_CONTACT`。ダウンロードと Web ページでは必須）を付け、ダウンロードと Web ページでは robots.txt とその Crawl-delay に従い、レジストリごとに一度に1件ずつ、少なくとも `REGISTRY_MIN_INTERVAL_MS` の間隔を空けて送信し、上限回数まで再試行します。ログイン、CAPTCHA、HTTP 401/403 に遭遇すると実行を停止します。その場合はレジストリに許可またはエクスポートを依頼してください。回避策は一切とりません。
- **ページの保存。** すべてのレスポンス本文は `ETag`/`Last-Modified` とともに保存される（`storage/app/registry-raw/`）ため、変更のないページは再ダウンロードされません。パーサーの修正後は `clinicaltrials:fetch --source=<key> --from-cache` で保存済みのページを再解析できます（繰り返す実行と同じ `--since` または `--full` を指定してください）。
- **公開されたままのレコード**（XML、CSV の行、Web ページ、PDF のテキスト）は各試験とともに保持され、正規化した内容が変わっていない試験は書き換えられません。
- **レイアウトの変化。** ダウンロードまたは Web ページで読み込むレジストリで、フル実行の解析件数が前回より大幅に少ない場合や、入力済みフィールドの割合が急落した実行は、理由とともに `partial` として記録されます。インポートが試験を削除することはありません。

### 重複登録

各レコードは所属するレジストリ（`source`）とそのレジストリでの識別子（`source_id`、レジストリごとに一意）を保持するため、同じレコードを再度インポートすると更新されます。レジストリは `config/registries.php` に一覧されています。

- **複数回登録された試験は1件として表示。** 各レコードのレジストリ識別子（自身の識別子と、ClinicalTrials.gov の副次 ID に含まれる ISRCTN 番号や EudraCT 番号など、レコードが参照する識別子）は正規化され（`ISRCTN 12345678` と `isrctn12345678` は同じ）、比較されます。識別子を共有するレコードはリンクされてグループにまとめられます。`config/registries.php` で最初に記載されているレジストリのレコードが表示され、それ以外はその試験の別登録として保持されます。スポンサーのプロトコル番号、助成金番号、タイトルが試験の統合に使われることはありません。
- **疑わしい一致は人が判断。** 同じレジストリの2つのレコードが識別子を共有する場合や、1つのレジストリのレコードを2件含むことになるグループは統合されず、そのリンクは `conflict` としてマークされます。運用者は `GET /api/admin/trial-links` でリンクを一覧し、確定または却下します。その判断は以降のインポートでも維持されます。共有していた識別子がなくなったリンクは削除され、グループが再計算されます。
- **1つの試験に1つのアラート。** 検索では各試験が1回だけ表示され、どの登録の変更も試験に対して照合されます。2つ目のレジストリが報告した同じ内容（そのレジストリへの試験の登場や同じ状況）が再度一致したり、メールで送られたりすることはありません。ダイジェストには試験ごとに1項目が含まれます。
- **既存データ。** アップグレード後に `php artisan trials:reconcile-duplicates` を一度実行して、保存済みの試験の識別子をインデックス化してください。インポートでは、保存する各試験についてこの処理が行われます。

### 検索

- ページ分割された検索で、タイトルと概要に含まれる語（MySQL 全文検索）、疾患・状態、キーワード、MeSH 用語、募集状況、フェーズ、試験の種類、スポンサー、国、都市、開始日・初回公開日・最終更新日、結果の有無、地点からの距離で絞り込めます。
- 試験ページにはすべての関連リストが表示されます。実施施設、介入、群、評価項目、適格基準、識別子、共同研究者、MeSH 用語、PubMed へのリンク付きの文献、インポートで記録された状況の変更です。

### アラート

- アラートは保存済み検索です。各インポートの後、条件に一致する新規および更新された試験が記録されます。
- メールは、試験が一致した時点ですぐに、または毎日・毎週のまとめとして送信されます。試験の募集開始などの状況の変更は、含めることも除外することもできます。
- 基本アラートは無料です。保存済み検索の上限はすべてのプランで同じです（`SAVED_SEARCH_LIMIT`、既定は 100）。以前のより低い上限を超えて作成された保存済み検索はそのまま保持されます。

### AI ダイジェスト（Pro AI と Team AI）

- 保存済み検索ごとに、下記の7言語のいずれかで、毎日または毎週の AI ダイジェストを設定できます。オフにしても基本アラートは引き続き動作します。
- バックエンドは、集計期間内に保存済み検索に一致した変更を選び出して件数を数え、保存済み検索の条件に基づいて順位を付けます（たとえば、検索で監視している国で新たに募集を開始した実施施設は、日付の変更より上位になります）。モデルが行うのは、記録された事実から各変更の短い事実ベースの要約を書くことだけです。
- 各ダイジェストには、保存済み検索、期間、一致した更新の件数、最も関連性の高い変更とその一致理由が表示され、試験ごとに NCT 番号、レジストリの更新日、ClinicalTrials.gov へのリンク（検証済みの NCT 番号からサーバーが生成）が示されます。欠けている事実は欠けていると明記され、最も関連性の高い更新だけを掲載している場合はその旨が表示されます。
- モデルに送られるのは公開レジストリの事実のみで、アカウント情報、メールアドレス、ユーザーのメモは送られません。モデルの回答は要求した変更に対応する JSON でなければならず、リンク、別の NCT 番号、適格性・治療・有効性に関する表現を含む要約は却下されます。
- AI がオフの場合、上限回数の再試行後も失敗した場合、または不正な出力が返された場合、ダイジェストは記録された変更から作成した事実ベースの要約を使用し、その理由（`ai_disabled`、`provider_error`、`invalid_output`）が配信回に記録されます。
- 要約は変更のバージョン、言語、プロンプトのバージョンごとにキャッシュされ、すべての購読者で再利用されます。関連性は保存済み検索ごとに計算されます。
- 各配信回には安定した識別子（受信者またはチーム、保存済み検索、期間）があり、受信者ごとに1件の配信行を持つため、スケジューラーの再実行やジョブの再試行で二重に送信されることはありません。送信前には、有料プランの利用権とチームのメンバーシップが改めて確認されます。
- 合成データによる2つのサンプル配信回を `/digests/samples` で、アカウントなしで閲覧できます。

### チーム（Team AI）

- Team AI の契約者は、1つのサブスクリプションで1つのチームを所有します。オーナーは登録済みユーザーをメールで招待し、招待されたユーザーは承諾した場合にのみ参加します。
- メンバーはチームの共有保存済み検索とダイジェストのレポートを閲覧でき、チームダイジェストをメールで受け取るかどうかを選べます。チーム、メンバー、共有検索を変更できるのはオーナーだけです。すべてのチーム用ルートでメンバーシップが確認されます。
- オーナーの Team AI サブスクリプションが終了すると、共有検索とレポートは一時停止されます。メンバー個人の AI ダイジェストは、そのメンバー自身のプランに従います。

### アカウント、プラン、課金

- メール確認付きの登録、JWT によるログイン、トークンの更新、ログアウト、パスワードのリセット、プロフィールとパスワードの変更。
- すべての保護されたルートでロールと権限を適用します：`view_clinical_trials`、`create_alerts`、`manage_users`、`manage_plans`、`manage_roles`、`manage_imports`。
- プラン（米ドル）：

  | プラン | 価格 | 内容 |
  |---|---|---|
  | Free | $0 | 検索、フィルター、試験の詳細、変更履歴、保存済み検索、基本メールアラート |
  | Pro AI | 月額 $19 | 自分の保存済み検索の AI ダイジェスト |
  | Team AI | 1チームあたり月額 $99 | 共有保存済み検索、共有 AI ダイジェスト、チームダイジェストごとのレポートページ |

- 新規アカウントはトライアルなしで Free から始まります。
- 有料プランは Stripe と Laravel Cashier で提供します。初回の契約には Stripe Checkout を使い、Pro AI と Team AI の間の切り替え（日割り計算あり）、期間終了時の解約、再開、Stripe カスタマーポータルに対応しています。Stripe の Webhook によってアカウントのプランが同期されます。解約後も、有料機能は支払い済み期間の終了まで利用できます。
- Basic、Pro、Enterprise はレガシープランです。販売は終了しており、AI 機能は付与されません。これらのプランの既存サブスクリプション、価格、Stripe の顧客はそのまま残され、契約者は引き続き解約、再開、カスタマーポータルの利用、現行プランへの移行ができます。

### Web アプリ

Laravel が配信する Vue 3 のシングルページアプリです。検索ページと試験ページ、一致した試験と AI ダイジェストの設定を含むアラート、AI ダイジェストのレポート、サンプルダイジェスト、チーム（メンバー、招待、共有検索）、アカウントとプラン、そしてユーザー・プラン・ロール・インポートの管理画面を備えています。

### 言語

Web アプリ、API メッセージ、メール、AI ダイジェストは、英語（既定）、ブラジルポルトガル語、スペイン語、簡体字中国語、繁体字中国語、日本語、韓国語で利用できます。

- Web アプリは、ブラウザーの言語が提供されていればその言語で起動し（それ以外は英語）、ヘッダー、ログインページ、アカウントページに言語メニューがあります。日付、数値、価格は選択した言語の形式で表示されます。
- 各アカウントは言語（`locale`）を保存します。登録時に設定され、メニューまたは `PUT /api/account/locale` で変更できます。メール（確認、パスワードのリセット、アラート、チームへの招待）は受信者の言語で送られ、AI ダイジェストはその保存済み検索に設定された言語を使います。
- API は `Accept-Language` ヘッダー（Web アプリは常に送信します）の言語で応答し、該当しない場合は英語にフォールバックします。レスポンスには `Content-Language` が含まれます。
- レジストリのデータ（試験のタイトル、概要、疾患・状態、実施施設）は ClinicalTrials.gov の言語のままで、翻訳されるのはその周りのインターフェースだけです。

翻訳は、バックエンドでは `lang/<locale>/` と `lang/<locale>.json`、Web アプリでは `resources/js/i18n/locales/` にあります。英語が原文で、他の言語にキーやプレースホルダーの欠落があるとテストが失敗します。

> 試験データは公開レジストリから取得したものです。医学的助言、治療の推奨、または試験に参加できる人についての判断ではありません。

## 技術スタック

2026-11-23 時点でインストールされているバージョンです。制約は `composer.json` と `package.json` にあります。

| レイヤー | 技術 |
|---|---|
| 言語とフレームワーク | PHP 8.6、Laravel 13.43 |
| データベース | MySQL 8.4 LTS（データ、全文検索、キュー、セッション） |
| キャッシュ | Predis 3.7 経由の Redis 8.10（キャッシュ、レート制限、JWT ブラックリスト） |
| 認証 | `tymon/jwt-auth` 2.3 による JWT |
| 課金 | Laravel Cashier 16.11 経由の Stripe |
| HTTP クライアント | ClinicalTrials.gov API 用の Guzzle 8.6（Laravel HTTP クライアント） |
| メール | Laravel Mail と通知（`MAIL_MAILER`、ローカル開発では `log`） |
| Web アプリ | Vue 3.5、Vue Router 5.5、Pinia 4.1、TypeScript 7.0、Public Sans（Google Fonts） |
| フロントエンドのビルド | Vite 8.3、`laravel-vite-plugin` 3.2、`@vitejs/plugin-vue` 6.0 |
| PHP のテストと品質 | PHPUnit 13.4、Mockery 1.6、Faker 1.24、Collision 8.9、Laravel Pint 1.32、Ignition 2.13（エラーページ） |
| フロントエンドのテストと品質 | Vitest 5.1、Vue Test Utils 2.6、jsdom 31、`vue-tsc` 3.3（`@typescript/typescript6` 6.0 上で実行） |
| ツール | Composer 2.11、Node.js 26、npm、Laravel Tinker 3.2 |
| コンテナ | Docker Compose：PHP-FPM、キューワーカー、スケジューラー、MySQL、Redis、nginx 1.30、Node.js（Vite） |
| CI | GitHub Actions |
| AI | OpenAI 互換の任意の Chat Completions API（Laravel HTTP クライアント経由、任意） |
| 外部サービス | ClinicalTrials.gov API v2、Stripe、OpenAI 互換の AI プロバイダー、PubMed（PMID によるリンク） |

Laravel Breeze 2.4 と Laravel Sail 1.71 は Laravel のスケルトンから開発用依存関係としてインストールされていますが、使用していません。このアプリは独自の Vue フロントエンドを持ち、独自の Docker Compose ファイルで動作します。`.ts` ファイルは TypeScript 7 がチェックします。TypeScript 7 のネイティブコンパイラーには `vue-tsc` が必要とする JavaScript API がもうないため、`npm run typecheck:vue` は公式の TypeScript 6 パッケージ `@typescript/typescript6` 上で `vue-tsc` を実行して（`scripts/vue-tsc.cjs`）`.vue` ファイルをチェックします。`npm run typecheck` は両方を実行します。phpredis 拡張機能はまだ PHP 8.6 でビルドできないため、Redis には Predis を使っています。`@vue/test-utils` が脆弱なバージョンの `glob` と `minimatch` にまだ依存しているため、`package.json` でこれらを修正済みのバージョンに上書きしています。

## クイックスタート

### 必要なもの

- Docker と Docker Compose
- ホストでポート `8000`（Web）、`3306`（MySQL）、`5173`（Vite）が空いていること。または `.env` で別のポートを設定すること（`APP_PORT`、`FORWARD_DB_PORT`、`VITE_PORT`）

### スタックの起動

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

`.env.example` には、`docker-compose.yml` で使われるデータベースの値があらかじめ設定されています。起動時に `app` コンテナは MySQL を待ち、`vendor/` がなければ `composer install` を実行し、`APP_KEY` と `JWT_SECRET` が空なら生成し、マイグレーションを実行します。`queue` と `scheduler` コンテナはキューに入ったジョブと毎日のスケジュールを実行し、`redis` はキャッシュを保持し、`node` コンテナは Vite の開発サーバーを動かします。

`http://localhost:8000` を開き、シードされた管理者 `admin@admin.com` / `password` でログインします。この認証情報はローカル開発専用です。

`--max-pages=1` は最初の1ページ分（100件の試験）をインポートします。これを付けないと、初回の実行でレジストリ全体を読み込むため、数時間かかります。

開発サーバーを動かす代わりに Web アプリをビルドするには：`docker-compose run --rm node npm run build`。

### その他のレジストリの設定

`.env` の `REGISTRY_CONTACT` に、レジストリの運営者が連絡できる URL またはメールアドレスを設定します。これがないと、ダウンロードや Web ページによるインポートは実行されません。`Dockerfile` の変更を取り込んだ後は（PDF のレジストリ用に `poppler-utils` をインストールします）、`docker-compose up -d --build` でイメージを再ビルドします。その後、レジストリを1つずつインポートします。例：`docker-compose exec app php artisan clinicaltrials:fetch --source=isrctn --max-pages=1`。

運営者のキーが必要なレジストリは、`REGISTRY_<KEY>_SERVICE_KEY` または `REGISTRY_<KEY>_API_KEY` からキーを読み込みます（`.env.example` を参照）。書面による許可が必要なレジストリは、`REGISTRY_PERMISSION_<KEY>=true` で許可が記録されるまで無効のままです。これはレジストリから書面で許可を得た後にのみ設定してください。

### 課金の設定

検索と基本アラートは Stripe なしで動作します。Pro AI と Team AI を販売するには：

1. `.env` に `STRIPE_KEY`、`STRIPE_SECRET`、`STRIPE_WEBHOOK_SECRET` を設定します。
2. Stripe で、USD の月額定期価格を2つ作成します。Pro AI 用に $19.00、Team AI 用に $99.00 です。それぞれの ID を `STRIPE_PRICE_PRO_AI` と `STRIPE_PRICE_TEAM_AI` に設定します。
3. `docker-compose exec app php artisan billing:check-prices` を実行します。ID をプランにコピーし、各価格が有効で、そのプランの金額を USD で毎月請求するものかをチェックします。問題があれば失敗し、その内容を示します。Checkout でも同じチェックが行われ、一致しない価格は拒否されます。
4. Stripe の Webhook を `https://<your-host>/stripe/webhook` に向け、`customer.subscription.*` と `invoice.*` のイベントを設定します。

既存のサブスクリプション：マイグレーション `2026_11_24_000001_add_free_and_ai_plans` は、Basic、Pro、Enterprise を、価格、Stripe の価格 ID、サブスクリプション、顧客を変更せずにレガシーとしてマークします。レガシープランの契約者はそのプランを維持し、これまでどおり請求されますが、AI 機能はありません。Webhook が引き続きそれらのサブスクリプションと照合できるよう、レガシープランの `stripe_price_id` は設定したままにしてください（管理画面のプランページ）。新しいプランへの対応付けは不要です。旧来の14日間の登録トライアル期間中のアカウントは、現在 Free を使用します。

### AI の設定

AI ダイジェストはプロバイダーなしでも動作し、その場合は事実ベースの要約を使います。モデルに要約を書かせるには、`.env` に次を設定します。

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # any OpenAI-compatible API
AI_API_KEY=...                          # server-side only
AI_MODEL=...                            # a model name the provider accepts
```

上限：`AI_TIMEOUT`（秒、30）、`AI_RETRIES`（2）と `AI_RETRY_SLEEP_MS`（2000）、`AI_MAX_INPUT_CHARS`（1リクエストあたり 12000 文字）、`AI_MAX_OUTPUT_TOKENS`（1200）、`AI_DIGEST_MAX_ITEMS`（1ダイジェストあたり10件の試験）。ログには各ダイジェストの状態、所要時間、キャッシュヒット、トークン数が記録されますが、API キーやモデルの入出力は記録されません。運用者は `GET /api/admin/digests` で、各配信回の生成方法（`ai`、`partial`、`fallback`）とフォールバックの理由を確認できます。

スケジューラーは毎日のダイジェストを 07:30 に、毎週のダイジェストを毎週月曜日の 07:30 にキューに追加し（`php artisan digests:dispatch daily|weekly`）、`queue` コンテナがそれらを生成して送信します。

### ローカルでの確認

1. シードされた管理者でログインし、1ページ分の試験をインポートします（上記参照）。
2. アラートページでアラートを作成します。Free で動作します。AI ダイジェストのセクションには、サンプルダイジェストとアップグレードの操作が表示されます。
3. `http://localhost:8000/digests/samples` を開いて、2つのサンプル配信回を確認します。
4. Stripe がない場合は、有料画面を試すために Tinker で手動でアカウントにプランを付与します。たとえば、`stripe_status` が `active` で、Pro AI または Team AI の `plan_id` を持つローカルの `subscriptions` 行を作成します。次に AI ダイジェストをオンにし、もう一度インポートを実行してから `php artisan digests:dispatch daily --end=tomorrow` を実行します（この場合、期間に今日が含まれます）。`MAIL_MAILER=log` の場合、ダイジェストのメールは `storage/logs/laravel.log` に書き出され、レポートは AI ダイジェストのページに表示されます。

## API

すべてのルートは `/api` の下にあり、JSON を返します。保護されたルートには `Authorization: Bearer <access_token>` が必要です。

| メソッド | ルート | アクセス | 説明 |
|---|---|---|---|
| `POST` | `/auth/register` | 公開 | アカウントを作成（`locale` は任意、省略時はリクエストの言語）。トークンを返す（201） |
| `POST` | `/auth/login` | 公開、1分あたり5回まで | トークン、または認証情報が無効な場合は HTTP 401 |
| `POST` | `/auth/refresh` | トークン | 新しいトークン |
| `POST` | `/auth/logout` | トークン | トークンを無効化（204） |
| `GET` | `/auth/me` | トークン | 現在のユーザー、ロール、権限、課金の概要 |
| `POST` | `/auth/forgot-password` | 公開 | リセット用リンクをメールで送信 |
| `POST` | `/auth/reset-password` | 公開 | メールで届いたトークンで新しいパスワードを設定 |
| `GET` | `/auth/verify-email/{user}/{hash}` | 署名付きリンク | メールアドレスを確認し、Web アプリにリダイレクト |
| `POST` | `/auth/email/verification-notification` | トークン | 確認用リンクを再送信 |
| `PUT` | `/account/profile`, `/account/password` | トークン | 名前とメールアドレス、またはパスワードを変更 |
| `PUT` | `/account/locale` | トークン | アカウントの言語を変更（`locale`：`en`、`pt_BR`、`es`、`zh_CN`、`zh_TW`、`ja`、`ko`） |
| `GET` | `/trials` | `view_clinical_trials` | 検索（試験ごとに1件の結果。各結果にその `registry`、`registry_url`、規約が求める `attribution` を含む）。フィルターは下記（レジストリのキーである `source[]` を含む）、`sort`、`page`、`per_page`（最大 100） |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | すべての関連リスト、正規化された `identifiers`、別登録（`also_registered_as`、または別のレコードの下に表示されるレコードでは `duplicate_of`）を含む試験 |
| `GET` `POST` | `/alerts` | `create_alerts`、確認済みメール | 保存済み検索の一覧または作成。チームの共有検索には `team_id`（オーナーが作成し、メンバーは一覧を取得） |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | オーナー。チームメンバーは共有検索を閲覧可 | 保存済み検索の取得・変更・削除。`ai_digest_enabled`、`ai_digest_frequency`（`daily`、`weekly`）、`ai_digest_language`（`en`、`pt_BR`、`es`、`zh_CN`、`zh_TW`、`ja`、`ko`）を含む |
| `GET` | `/alerts/{id}/matches` | オーナー、チームメンバー | 一致した試験 |
| `GET` | `/plans` | 公開 | Free、Pro AI、Team AI と USD 価格および機能 |
| `GET` | `/digests/samples` | 公開 | 合成データによる2つのサンプルダイジェスト配信回 |
| `GET` | `/digests`, `/digests/{id}` | 確認済みメール。受信者またはチームメンバー | ダイジェストの配信回（チームの場合は `?team_id=`）と、その内容を含むレポート |
| `GET` `POST` | `/teams` | 確認済みメール | 自分のチーム。チームを作成（Team AI） |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | メンバー（閲覧）、オーナー（変更、削除） | メンバーを含むチーム |
| `PATCH` | `/teams/{id}/membership` | メンバー | チームダイジェストをメールで受け取るかどうか |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | オーナー | 登録済みユーザーをメールで招待。招待を取り消す |
| `DELETE` | `/teams/{id}/members/{user}` | オーナー、または退出するメンバー本人 | メンバーを削除 |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | 招待されたユーザー | 自分のメールアドレス宛ての招待。承諾または辞退 |
| `GET` | `/billing` | 確認済みメール | 有効なプランとサブスクリプション |
| `POST` | `/billing/plan` | 確認済みメール | プランを選択。支払いが必要な場合は `checkout_url` を返す |
| `POST` | `/billing/cancel`, `/billing/resume` | 確認済みメール | 期間終了時に解約、またはサブスクリプションを継続 |
| `GET` | `/billing/portal` | 確認済みメール | Stripe カスタマーポータルの URL |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | ユーザーの一覧。ロールとプランの変更 |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | レガシーを含むすべてのプラン。プランの管理 |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | ロールの権限を管理 |
| `GET` `POST` | `/admin/imports` | `manage_imports` | インポートの実行履歴。インポートをキューに追加（202） |
| `GET` | `/admin/digests` | `manage_imports` | 状態、生成方法、フォールバックの理由、指標を含むダイジェスト配信回（内容は含まない） |
| `GET` | `/admin/trial-links` | `manage_imports` | レジストリ識別子を共有するレコード間のリンク。`status` = `conflict`（既定）、`auto`、`confirmed`、`rejected` |
| `PATCH` | `/admin/trial-links/{id}` | `manage_imports` | リンクを確定または却下（`status`：`confirmed` または `rejected`）。レコードは再グループ化される |

検索フィルター：`q`、`condition`、`keyword`、`mesh_term`、`sponsor`、`country`、`city`、`status[]`、`phase[]`、`study_type[]`、`has_results`、`start_date_from`/`_to`、`first_posted_from`/`_to`、`last_update_from`/`_to`、および `latitude` + `longitude` + `radius_km`。`sort` には `-last_update_post_date`（既定）、`last_update_post_date`、`±start_date`、`±study_first_post_date`、`±brief_title`、`relevance` を指定できます。

トークンがない、または無効な場合は 401、権限がない場合や他のユーザー・チームのレコードの場合は 403、プランなしで有料機能（AI ダイジェスト、チームワークスペース）を使った場合は 402、保存済み検索の上限に達した場合は 403、入力が無効な場合はフィールドごとのエラー付きで 422 を返します。

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 仕組み

```text
Import:  scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
           → ClinicalTrialsGovClient (pages, retries) → StudyMapper → ClinicalTrialSynchronizer (one transaction per study)
           → ClinicalTrialsImported event → MatchAlertsJob → AlertMatcher → AlertNotifier (email)
Digests: scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identity, lock, entitlement)
           → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider or factual text) → DigestDeliverer (email)
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` にはインポート処理があります。各レジストリは `StudyRegistryClient` と `StudyPayloadMapper` を持つ `RegistrySource`（`app/Services/Registries/`）で、`clinicaltrials:fetch --source=<key>` で1つを選びます（既定は `clinicaltrials_gov`）。レジストリごとに独自の増分カーソルを持ちます。マッパーはペイロードの純粋関数で、永続化はシンクロナイザーが担当します。
- `app/Services/Registries/` には、レジストリの一覧（`SourceRegistry`）、識別子の正規化（`IdentifierParser`）、重複排除（`DuplicateLinker`、各試験のトランザクション内で実行）があります。インポーターがレジストリにアクセスする唯一の手段は `Fetching/RegistryFetcher` です。`Parsing/` には HTML、CSV、XLSX、PDF テキスト、固定幅テーブルのリーダーがあり、`Sources/<Registry>/` には各インポーター（法務レビュー付きのソース、クライアント、マッパー）があります。
- `app/Services/ClinicalTrials/Search/` は、`AppServiceProvider` に登録された小さな `TrialFilter` クラス群から検索を組み立てます。アラートも同じ条件とフィルターを再利用します。
- `app/Services/ClinicalTrials/Changes/` には、試験の追跡対象となる事実と、それらを比較する純粋な `ChangeDetector` があります。
- `app/Services/Alerts/` は保存済み検索の上限を適用し、インポート結果を照合してメールを送信します。`app/Services/Billing/` にはプランのルールと `Entitlements` があり、Stripe には `SubscriptionGateway` インターフェースを通じてアクセスします。`app/Services/Teams/` はチームと招待を管理します。
- `app/Services/Digests/` はダイジェストを作成します。モデルには `AiProvider` インターフェース（`OpenAiCompatibleProvider`）を通じてアクセスします。
- `app/Http/` ではコントローラーを薄く保ちます。Form Request が検証し、サービスが判断し、API Resource が JSON を整形します。
- Docker スタックは7つのサービスで構成されます：`app`（PHP 8.6 FPM）、`queue`、`scheduler`、`mysql`（MySQL 8.4）、`redis`（Redis 8.10。キャッシュストアで、レートリミッターと JWT ブラックリストにも使用）、`nginx`、`node`（Node.js 26 と Vite）。phpredis 拡張機能はまだ PHP 8.6 でビルドできないため、Laravel は Predis（`REDIS_CLIENT=predis`）を通じて Redis と通信します。

## 開発

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- PHP のテストは専用の MySQL データベース `clinical_saas_test` で実行されます。このデータベースは、MySQL ボリュームの初回作成時に `docker/mysql/init/` によって作成されます。既存のボリュームの場合は、一度だけ作成してください：`docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`。
- インポートのテストは `Http::fake()` と `tests/Fixtures/` に記録された API レスポンスを使います。課金のテストは `Tests\Fakes\FakeSubscriptionGateway` を、ダイジェストのテストは `Tests\Fakes\FakeAiProvider` を使い、メールはフェイクにしています。テストが ClinicalTrials.gov、Stripe、AI プロバイダーを呼び出すことはありません。
- GitHub Actions：`.github/workflows/packages.yml` は Composer と npm のロックファイルを監査し（*packages* バッジ）、`.github/workflows/ci.yml` は Pint、PHP のテスト、型チェック、Vue のテスト、ビルドを、`main` へのプッシュとプルリクエスト、タグ、そして Actions タブからの手動実行で実行します。ブランチは `dev` から `hom`、`main` へと進みます。

スキーマは `database/migrations/` のマイグレーションだけで定義されます。スキーマを変更するたびに新しいマイグレーションを追加し、実行済みのマイグレーションは編集しないでください。

## プロジェクト構成

```text
app/
  Console/Commands/      clinicaltrials:fetch, trials:reconcile-duplicates, alerts:send-digests, digests:dispatch, billing:check-prices
  Enums/                 statuses, phases, permissions, alert and digest frequency, team role
  Http/                  Controllers/Api (and Admin/), Requests/, Resources/, Middleware/
  Jobs/, Events/, Listeners/, Notifications/, Policies/
  Models/                User, Role, Permission, Plan, Subscription, Alert, AlertMatch, ImportRun,
                         Team, TeamMember, TeamInvitation, Digest, DigestItem, DigestDelivery, ChangeSummary
  Models/ClinicalTrials/ ClinicalTrial, its related lists and ClinicalTrialChange
  Services/              Auth/, ClinicalTrials/{Import,Search,Changes}, Registries/{Fetching,Parsing,Sources,...},
                         Alerts/, Billing/, Teams/, Digests/, Ai/, Admin/
config/                  clinicaltrials.php, registries.php, billing.php, ai.php, auth.php, jwt.php
lang/                    API, email and digest text per language (en, pt_BR, es, zh_CN, zh_TW, ja, ko)
database/                migrations/, factories/, seeders/
docs/                    logo, registries evaluation
resources/js/            Vue app: pages/, components/, stores/, api/, i18n/ (catalogs per language)
routes/                  api.php, web.php (web app), console.php (schedule)
tests/                   Feature/, Unit/, Fixtures/
```

## データソース

試験データは、米国国立医学図書館（U.S. National Library of Medicine）が運営する [ClinicalTrials.gov](https://clinicaltrials.gov/) から取得しています。記録は登録された試験を説明するもので、医学的助言ではありません。試験の詳細に依拠する前に、必ず公式の記録を確認してください。その他のレジストリは[その他のレジストリ](#その他のレジストリ)に一覧されており、各レジストリのデータは出典を明記して表示されます。[docs/registries-evaluation.md](docs/registries-evaluation.md) には、調査したすべてのレジストリの法務レビューが記録されています。

## ライセンス

[MIT](LICENSE-MIT)。
