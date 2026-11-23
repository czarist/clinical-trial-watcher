<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">從 ClinicalTrials.gov 匯入臨床研究並進行搜尋，當您追蹤的研究新登錄、有所變更或開始招募時，透過電子郵件通知您。</h3>

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
  <a href="#功能">功能</a> ·
  <a href="#技術堆疊">技術堆疊</a> ·
  <a href="#快速開始">快速開始</a> ·
  <a href="#api">API</a> ·
  <a href="#運作方式">運作方式</a> ·
  <a href="#開發">開發</a> ·
  <a href="ROADMAP.md">開發藍圖</a> ·
  <a href="CHANGELOG.md">變更紀錄</a>
</p>

## 功能

### 從 ClinicalTrials.gov 匯入

- **先匯入整個登錄資料庫，之後只匯入有變更的部分。** `php artisan clinicaltrials:fetch` 會讀取正式環境的 API v2（`https://clinicaltrials.gov/api/v2/studies`，並驗證 TLS），依 `nextPageToken` 逐頁讀取所有頁面，並記錄每次執行。之後的執行只會請求自上次成功執行以來有更新的研究。
- **每天執行。** 排程器會在 03:00（`CLINICAL_TRIALS_SCHEDULE_TIME`）將匯入排入佇列，由佇列工作程序執行，一次只執行一個匯入。
- **可安全地重複執行。** 研究以 NCT 編號比對。每項研究及其相關清單都在同一個交易中儲存，且每份清單都會以目前的內容取代，空清單也不例外。失敗的研究會回復並計入統計，執行則會繼續進行。
- **失敗會明確呈現。** 速率限制（HTTP 429）、伺服器錯誤及連線失敗會以指數退避重試，次數有上限。無法讀取登錄資料庫的執行會記錄為失敗，指令也會以錯誤結束。
- **變更會連同變更前後的值一併追蹤。** 新研究會記錄為新研究，並成為比較基準。之後的匯入會比較整體招募狀態、試驗地點（新增、移除或試驗地點狀態變更）、開始與完成日期（保留原始精確度：只有年月的日期仍維持年月）以及已發布的結果，並以結構化的詳細資料儲存。試驗地點清單依機構與地點比對，因此登錄資料庫列出的順序改變並不算變更。每項變更都有穩定的指紋，因此重試或重複的匯入不會將同一項變更記錄或通知兩次。
- **不完整的日期會經過正規化**（`2024` → `2024-01-01`、`2024-06` → `2024-06-01`），完整的研究資料則保存在 `raw_json` 中。

### 其他登錄資料庫

另有十二個登錄資料庫可透過 `php artisan clinicaltrials:fetch --source=<key>` 匯入。只有 ClinicalTrials.gov 會依每日排程執行。

| 鍵 | 登錄資料庫 | 讀取方式 | 增量 | 商業使用 |
|---|---|---|---|---|
| `ctis` | CTIS（歐盟/歐洲經濟區） | 入口網站 JSON API | 是（最後更新） | 是，須註明 EMA |
| `isrctn` | ISRCTN | XML API | 是（最後編輯） | 是（CC BY / CC0） |
| `euctr` | EU Clinical Trials Register | 純文字匯出 | 僅新試驗 | 是，須註明該登錄資料庫 |
| `drks` | DRKS（德國） | JSON 傾印；僅 CC BY 4.0 紀錄（2025 年起登錄或更新） | 是（最後更新） | 是（CC BY 4.0） |
| `reec` | REEC（西班牙） | REST API | 最近 90 天的登錄 | 是，須註明 AEMPS |
| `rebec` | ReBEC（巴西） | 網頁 | 是（最後核准） | 未確認 |
| `renis` | RENIS（阿根廷） | XLSX 檔案 | 否 | 是（CC BY 4.0） |
| `health_canada` | Health Canada | JSON API | 否 | 是（OGL Canada） |
| `tfda` | TFDA（台灣） | CSV 的 ZIP 壓縮檔 | 否 | 是（Open Government Data License） |
| `ppb_kenya` | Pharmacy and Poisons Board（肯亞） | 網頁 | 是（核准日期） | 未確認 |
| `eda_egypt` | Egyptian Drug Authority | PDF | 否 | 未確認 |
| `moz_ukraine` | 烏克蘭衛生部命令 | PDF 附件 | 最新命令 | 未確認 |

- **每個匯入器都通過了法律審查**，審查紀錄在其來源類別及 [docs/registries-evaluation.md](docs/registries-evaluation.md)（第 7 節）中；該文件也列出了目前尚無法匯入的登錄資料庫及各自所需的條件（WHO ICTRP、UMIN-CTR、CRIS、ANZCTR、HRA 等）。
- **條款未確認允許商業使用的登錄資料庫，其紀錄會附上出處免費顯示，但不會納入 AI 摘要**，直到該登錄資料庫以書面確認允許再利用。
- **有禮貌的存取。** 請求會帶有包含聯絡地址的 user agent（`REGISTRY_CONTACT`，下載與網頁為必填），下載與網頁會遵守 robots.txt 及其 Crawl-delay，每個登錄資料庫一次只送出一個請求且間隔至少 `REGISTRY_MIN_INTERVAL_MS`，重試次數有上限。遇到登入、驗證碼或 HTTP 401/403 時會停止執行：請向該登錄資料庫申請許可或匯出檔案。絕不繞過任何限制。
- **儲存的頁面。** 每個回應本文都會連同其 `ETag`/`Last-Modified` 一併儲存（`storage/app/registry-raw/`），因此未變更的頁面不會重新下載；修正剖析器後，`clinicaltrials:fetch --source=<key> --from-cache` 會重新剖析已儲存的頁面（請傳入與要重複的執行相同的 `--since` 或 `--full`）。
- **依發布原樣保存的紀錄**（XML、CSV 列、網頁、PDF 文字）會隨每項研究保存，正規化內容未變更的研究不會重新寫入。
- **版面變更。** 透過下載或網頁讀取的登錄資料庫，若完整執行剖析出的紀錄遠少於上次，或任何執行中已填欄位的比例驟降，該次執行會記錄為 `partial` 並註明原因。匯入絕不會刪除研究。

### 重複登錄

每筆紀錄都會保存所屬的登錄資料庫（`source`）及其在該資料庫中的識別碼（`source_id`），識別碼在每個登錄資料庫內不會重複，因此再次匯入同一筆紀錄時會更新該紀錄。登錄資料庫列於 `config/registries.php`。

- **登錄多次的研究只顯示一次。** 每筆紀錄的登錄識別碼（本身的識別碼及其引用的識別碼，例如 ClinicalTrials.gov 次要 ID 中的 ISRCTN 或 EudraCT 編號）會先經過正規化（`ISRCTN 12345678` 與 `isrctn12345678` 視為相同）再進行比對。共用某個識別碼的紀錄會建立連結並歸為一組：顯示在 `config/registries.php` 中排在最前面的登錄資料庫的紀錄，其餘紀錄則保留為它的其他登錄。贊助者的計畫書編號、補助編號及標題絕不會用來合併研究。
- **有疑慮的比對交由人工判斷。** 同一個登錄資料庫中共用某個識別碼的兩筆紀錄，或會包含同一登錄資料庫兩筆紀錄的群組，都不會合併：它們的連結會標記為 `conflict`。營運人員可在 `GET /api/admin/trial-links` 列出連結並確認或拒絕；之後的匯入會保留此決定。共用識別碼消失的連結會被移除，並重新計算群組。
- **一項研究，一則提醒。** 搜尋時每項研究只列出一次；任何一筆登錄的變更都會以該研究進行比對；第二個登錄資料庫回報的相同消息（研究出現在該資料庫中，或相同的狀態）不會再次比對或寄送電子郵件。每期摘要中每項研究只有一個項目。
- **既有資料。** 升級後執行一次 `php artisan trials:reconcile-duplicates`，為已儲存研究的識別碼建立索引；匯入時則會對儲存的每項研究自動執行此作業。

### 搜尋

- 分頁搜尋，可依下列條件篩選：標題與摘要中的字詞（MySQL 全文檢索）、疾病或狀況、關鍵字、MeSH 詞彙、招募狀態、試驗分期、研究類型、贊助者、國家、城市、開始日期、首次發布日期與最後更新日期、是否有結果，以及與某個地點的距離。
- 研究頁面包含所有相關清單：試驗地點、介入措施、試驗組別、結果指標、納入條件、識別碼、合作單位、MeSH 詞彙、附 PubMed 連結的相關文獻，以及匯入時記錄的狀態變更。

### 提醒

- 提醒就是一個已儲存搜尋。每次匯入後，系統會記錄符合條件的新研究及已更新研究。
- 電子郵件可在研究符合時立即寄送，或以每日、每週摘要寄送。狀態變更（例如研究開始招募）可選擇包含或排除。
- 基本提醒免費。所有方案的已儲存搜尋數量上限都相同（`SAVED_SEARCH_LIMIT`，預設為 100）；在較低上限時期超額建立的已儲存搜尋會予以保留。

### AI 摘要（Pro AI 及 Team AI）

- 已儲存搜尋可以設定每日或每週的 AI 摘要，語言可選擇下方七種語言中的任何一種。關閉 AI 摘要後，基本提醒仍會繼續運作。
- 後端會挑選報告期間內符合該已儲存搜尋的變更、加以計數，並依已儲存搜尋的條件排序（例如，在搜尋所監看國家中新開始招募的試驗地點，排序會高於日期變更）。模型只負責根據已記錄的事實，為每項變更撰寫簡短的事實摘要。
- 每期摘要會顯示已儲存搜尋、期間、符合的更新數量、最相關的變更及其符合原因，並為每項研究列出 NCT 編號、登錄資料庫更新日期及 ClinicalTrials.gov 連結（由伺服器根據驗證過的 NCT 編號產生）。缺少的事實會明確標示為缺少；若摘要只列出最相關的更新，也會加以說明。
- 傳送給模型的只有公開的登錄資料庫事實：不包含帳戶資料、電子郵件地址或使用者備註。模型的回覆必須是對應所請求變更的 JSON；含有連結、其他 NCT 編號，或涉及參與資格、治療或療效措辭的摘要都會被拒絕。
- 當 AI 已關閉、在有限次數重試後仍失敗，或回傳無效內容時，摘要會改用根據已記錄變更產生的事實摘要，並在該期記錄原因（`ai_disabled`、`provider_error`、`invalid_output`）。
- 摘要內容會依變更版本、語言及提示詞版本快取，並供所有訂閱者重複使用；相關性則依各個已儲存搜尋分別計算。
- 每一期都有穩定的識別（收件者或團隊、已儲存搜尋及期間），且每位收件者各有一筆寄送紀錄，因此排程器重新執行或工作重試都不會重複寄送。寄送前會再次檢查付費存取權及團隊成員資格。
- 兩期使用虛構資料的摘要範例位於 `/digests/samples`，無需帳戶即可開啟。

### 團隊（Team AI）

- Team AI 訂閱者在一份訂閱下擁有一個團隊。擁有者可透過電子郵件邀請已註冊的使用者；對方必須接受邀請才會加入。
- 成員可以查看團隊共用的已儲存搜尋及摘要報告，並選擇是否以電子郵件接收團隊摘要。只有擁有者可以變更團隊、成員及共用搜尋。每個團隊路由都會檢查成員資格。
- 擁有者的 Team AI 訂閱結束後，共用搜尋及報告會暫停；成員自己的 AI 摘要則取決於其本身的方案。

### 帳戶、方案與計費

- 註冊並確認電子郵件、以 JWT 登入、更新權杖、登出、重設密碼，以及變更個人資料與密碼。
- 每個受保護的路由都會強制檢查角色與權限：`view_clinical_trials`、`create_alerts`、`manage_users`、`manage_plans`、`manage_roles` 及 `manage_imports`。
- 方案（以美元計價）：

  | 方案 | 價格 | 包含內容 |
  |---|---|---|
  | Free | $0 | 搜尋、篩選、研究詳細資料、變更紀錄、已儲存搜尋及基本電子郵件提醒 |
  | Pro AI | 每月 $19 | 為您自己的已儲存搜尋提供 AI 摘要 |
  | Team AI | 每個團隊每月 $99 | 共用已儲存搜尋、共用 AI 摘要，以及每份團隊摘要的報告頁面 |

- 新帳戶從 Free 開始，不提供試用期。
- 付費方案透過 Stripe 與 Laravel Cashier 處理：首次訂閱使用 Stripe Checkout，可在 Pro AI 與 Team AI 之間切換並按比例計費，可於計費週期結束時取消、恢復訂閱，並提供 Stripe 客戶入口網站。Stripe webhook 會讓帳戶的方案保持同步。取消後，付費功能會持續到已付費期間結束為止。
- Basic、Pro 及 Enterprise 為舊方案。這些方案已不再販售，也不提供任何 AI 功能；其既有訂閱、價格及 Stripe 客戶維持不變，訂閱者仍可取消、恢復、使用客戶入口網站或改用目前的方案。

### 網頁應用程式

由 Laravel 提供的 Vue 3 單頁應用程式：搜尋與研究頁面、提醒及其符合項目與 AI 摘要設定、AI 摘要報告、摘要範例、團隊（成員、邀請及共用搜尋）、帳戶與方案，以及使用者、方案、角色與匯入的管理功能。

### 語言

網頁應用程式、API 訊息、電子郵件及 AI 摘要提供英文（預設）、巴西葡萄牙文、西班牙文、簡體中文、繁體中文、日文及韓文。

- 若提供瀏覽器所使用的語言，網頁應用程式會以該語言啟動（否則使用英文），並在頁首、登入頁面及帳戶頁面提供語言選單。日期、數字及價格會依所選語言顯示。
- 每個帳戶都會儲存其語言（`locale`）：於註冊時設定，並可透過選單或 `PUT /api/account/locale` 變更。電子郵件（確認信、密碼重設、提醒、團隊邀請）使用收件者的語言；AI 摘要則使用其已儲存搜尋所選的語言。
- API 會以 `Accept-Language` 標頭的語言回應（網頁應用程式一律會傳送此標頭），否則退回使用英文。回應會帶有 `Content-Language`。
- 登錄資料（研究標題、摘要、疾病或狀況、試驗地點）維持 ClinicalTrials.gov 上的語言；只有周邊的介面會翻譯。

後端的翻譯位於 `lang/<locale>/` 及 `lang/<locale>.json`，網頁應用程式的翻譯位於 `resources/js/i18n/locales/`。英文為來源語言：若其他語言缺少鍵值或預留位置，測試就會失敗。

> 研究資料來自公開的登錄資料庫。這些資料並非醫療建議、治療建議，也不代表誰能參加研究的判定。

## 技術堆疊

版本為 2026-11-23 安裝時的版本。版本限制請見 `composer.json` 及 `package.json`。

| 層級 | 技術 |
|---|---|
| 語言與框架 | PHP 8.6、Laravel 13.43 |
| 資料庫 | MySQL 8.4 LTS（資料、全文檢索、佇列及工作階段） |
| 快取 | Redis 8.10，透過 Predis 3.7（快取、速率限制、JWT 黑名單） |
| 驗證 | JWT，使用 `tymon/jwt-auth` 2.3 |
| 計費 | Stripe，透過 Laravel Cashier 16.11 |
| HTTP 用戶端 | Guzzle 8.6（Laravel HTTP 用戶端），用於 ClinicalTrials.gov API |
| 電子郵件 | Laravel Mail 及通知（`MAIL_MAILER`；本機開發時為 `log`） |
| 網頁應用程式 | Vue 3.5、Vue Router 5.5、Pinia 4.1、TypeScript 7.0、Public Sans（Google Fonts） |
| 前端建置 | Vite 8.3、`laravel-vite-plugin` 3.2、`@vitejs/plugin-vue` 6.0 |
| PHP 測試與品質 | PHPUnit 13.4、Mockery 1.6、Faker 1.24、Collision 8.9、Laravel Pint 1.32、Ignition 2.13（錯誤頁面） |
| 前端測試與品質 | Vitest 5.1、Vue Test Utils 2.6、jsdom 31、`vue-tsc` 3.3（執行於 `@typescript/typescript6` 6.0） |
| 工具 | Composer 2.11、Node.js 26、npm、Laravel Tinker 3.2 |
| 容器 | Docker Compose：PHP-FPM、佇列工作程序、排程器、MySQL、Redis、nginx 1.30、Node.js（Vite） |
| CI | GitHub Actions |
| AI | 任何相容於 OpenAI 的 chat completions API，透過 Laravel HTTP 用戶端呼叫（選用） |
| 外部服務 | ClinicalTrials.gov API v2、Stripe、相容於 OpenAI 的 AI 供應商、PubMed（依 PMID 連結） |

Laravel Breeze 2.4 及 Laravel Sail 1.71 是隨 Laravel 骨架安裝的開發相依套件，但並未使用：本應用程式有自己的 Vue 前端，並以自己的 Docker Compose 檔案執行。TypeScript 7 負責檢查 `.ts` 檔案。由於其原生編譯器已不再提供 `vue-tsc` 所需的 JavaScript API，`npm run typecheck:vue` 會在官方的 TypeScript 6 套件 `@typescript/typescript6` 上執行 `vue-tsc`（`scripts/vue-tsc.cjs`）來檢查 `.vue` 檔案；`npm run typecheck` 則會同時執行兩者。Redis 使用 Predis，因為 phpredis 擴充套件目前還無法在 PHP 8.6 上建置。`package.json` 以修補過的版本覆寫 `glob` 及 `minimatch`，因為 `@vue/test-utils` 仍相依於有漏洞的版本。

## 快速開始

### 需求

- Docker 及 Docker Compose
- 主機上的連接埠 `8000`（網頁）、`3306`（MySQL）及 `5173`（Vite）必須可用，或在 `.env` 中設定其他連接埠（`APP_PORT`、`FORWARD_DB_PORT`、`VITE_PORT`）

### 啟動服務

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

`.env.example` 已包含 `docker-compose.yml` 所使用的資料庫設定值。啟動時，`app` 容器會等待 MySQL 就緒，若缺少 `vendor/` 則執行 `composer install`，在 `APP_KEY` 及 `JWT_SECRET` 為空時產生它們，並執行遷移。`queue` 及 `scheduler` 容器負責執行佇列中的工作及每日排程，`redis` 存放快取，`node` 容器則執行 Vite 開發伺服器。

開啟 `http://localhost:8000`，並以預先植入的管理員帳戶 `admin@admin.com` / `password` 登入。這組帳密僅供本機開發使用。

`--max-pages=1` 會匯入第一頁共 100 項研究。若不加此選項，第一次執行會讀取整個登錄資料庫，需要數小時。

若要建置網頁應用程式而不執行開發伺服器：`docker-compose run --rm node npm run build`。

### 計費設定

搜尋及基本提醒不需要 Stripe 即可運作。若要販售 Pro AI 及 Team AI：

1. 在 `.env` 中設定 `STRIPE_KEY`、`STRIPE_SECRET` 及 `STRIPE_WEBHOOK_SECRET`。
2. 在 Stripe 中建立兩個以美元計價的每月定期價格：Pro AI 為 $19.00，Team AI 為 $99.00。將其 ID 填入 `STRIPE_PRICE_PRO_AI` 及 `STRIPE_PRICE_TEAM_AI`。
3. 執行 `docker-compose exec app php artisan billing:check-prices`。此指令會將 ID 複製到各方案，並檢查每個價格是否已啟用且每月以美元收取該方案的金額；否則會失敗並指出問題所在。Checkout 也會執行相同的檢查，並拒絕不相符的價格。
4. 將 Stripe webhook 指向 `https://<your-host>/stripe/webhook`，並選取 `customer.subscription.*` 及 `invoice.*` 事件。

既有訂閱：遷移 `2026_11_24_000001_add_free_and_ai_plans` 會將 Basic、Pro 及 Enterprise 標記為舊方案，但不會變更其價格、Stripe 價格 ID、訂閱或客戶。使用舊方案的訂閱者會保留該方案，計費方式不變，但不提供 AI 功能。請保留舊方案的 `stripe_price_id` 設定（位於「管理」中的「方案」頁面），讓 webhook 能持續對應其訂閱；不需要將任何項目對應到新方案。仍在舊版 14 天註冊試用期內的帳戶，現在改用 Free。

### AI 設定

AI 摘要不需要供應商也能運作：此時會使用事實摘要。若要由模型撰寫摘要，請在 `.env` 中設定：

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # any OpenAI-compatible API
AI_API_KEY=...                          # server-side only
AI_MODEL=...                            # a model name the provider accepts
```

限制：`AI_TIMEOUT`（秒，30）、`AI_RETRIES`（2）及 `AI_RETRY_SLEEP_MS`（2000）、`AI_MAX_INPUT_CHARS`（每次請求 12000 個字元）、`AI_MAX_OUTPUT_TOKENS`（1200）及 `AI_DIGEST_MAX_ITEMS`（每期摘要 10 項研究）。日誌會記錄每期摘要的狀態、耗時、快取命中次數及權杖數量，但絕不記錄 API 金鑰或模型的輸入與輸出。營運人員可在 `GET /api/admin/digests` 查看每一期的產生方式（`ai`、`partial` 或 `fallback`）及改用事實摘要的原因。

排程器會在每天 07:30 將每日摘要排入佇列，並在每週一 07:30 將每週摘要排入佇列（`php artisan digests:dispatch daily|weekly`）；由 `queue` 容器負責產生並寄送。

### 在本機確認

1. 以預先植入的管理員帳戶登入，並匯入一頁研究（見上文）。
2. 在「提醒」頁面建立提醒：Free 方案即可使用。其 AI 摘要區段會顯示摘要範例及升級操作。
3. 開啟 `http://localhost:8000/digests/samples` 查看兩期摘要範例。
4. 若沒有 Stripe，可在 Tinker 中手動為帳戶指定方案以試用付費畫面，例如建立一筆本機 `subscriptions` 資料列，將 `stripe_status` 設為 `active`，並使用 Pro AI 或 Team AI 的 `plan_id`。接著開啟 AI 摘要，再執行一次匯入及 `php artisan digests:dispatch daily --end=tomorrow`（此時期間會包含今天）；在 `MAIL_MAILER=log` 的設定下，摘要電子郵件會寫入 `storage/logs/laravel.log`，報告則會出現在「AI 摘要」頁面。

## API

所有路由都位於 `/api` 之下，並回傳 JSON。受保護的路由需要 `Authorization: Bearer <access_token>`。

| 方法 | 路由 | 存取權限 | 說明 |
|---|---|---|---|
| `POST` | `/auth/register` | 公開 | 建立帳戶（可選填 `locale`，否則使用請求的語言）；回傳權杖（201） |
| `POST` | `/auth/login` | 公開，每分鐘 5 次嘗試 | 回傳權杖，帳密無效時回傳 HTTP 401 |
| `POST` | `/auth/refresh` | 權杖 | 新權杖 |
| `POST` | `/auth/logout` | 權杖 | 使權杖失效（204） |
| `GET` | `/auth/me` | 權杖 | 目前使用者、角色、權限及計費摘要 |
| `POST` | `/auth/forgot-password` | 公開 | 以電子郵件寄送重設連結 |
| `POST` | `/auth/reset-password` | 公開 | 使用電子郵件中的權杖設定新密碼 |
| `GET` | `/auth/verify-email/{user}/{hash}` | 簽署連結 | 確認電子郵件並重新導向至網頁應用程式 |
| `POST` | `/auth/email/verification-notification` | 權杖 | 重新寄送確認連結 |
| `PUT` | `/account/profile`, `/account/password` | 權杖 | 變更名稱與電子郵件，或變更密碼 |
| `PUT` | `/account/locale` | 權杖 | 變更帳戶語言（`locale`：`en`、`pt_BR`、`es`、`zh_CN`、`zh_TW`、`ja`、`ko`） |
| `GET` | `/trials` | `view_clinical_trials` | 搜尋，每項研究一筆結果；篩選條件見下方（包括 `source[]`，即登錄資料庫的鍵值），另有 `sort`、`page`、`per_page`（最多 100） |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | 研究及其所有相關清單、正規化後的 `identifiers`，以及其他登錄（`also_registered_as`；若此紀錄顯示在另一筆紀錄之下，則為 `duplicate_of`） |
| `GET` `POST` | `/alerts` | `create_alerts`，已確認電子郵件 | 列出或建立已儲存搜尋；使用 `team_id` 存取團隊共用的已儲存搜尋（擁有者建立，成員列出） |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | 擁有者；團隊成員可讀取共用的項目 | 讀取、變更或刪除已儲存搜尋，包括 `ai_digest_enabled`、`ai_digest_frequency`（`daily`、`weekly`）及 `ai_digest_language`（`en`、`pt_BR`、`es`、`zh_CN`、`zh_TW`、`ja`、`ko`） |
| `GET` | `/alerts/{id}/matches` | 擁有者、團隊成員 | 符合的研究 |
| `GET` | `/plans` | 公開 | Free、Pro AI 及 Team AI，含美元價格及功能 |
| `GET` | `/digests/samples` | 公開 | 兩期使用虛構資料的摘要範例 |
| `GET` | `/digests`, `/digests/{id}` | 已確認電子郵件；收件者或團隊成員 | 摘要各期（團隊使用 `?team_id=`）及含內容的報告 |
| `GET` `POST` | `/teams` | 已確認電子郵件 | 您的團隊；建立團隊（Team AI） |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | 成員（讀取）、擁有者（變更、刪除） | 團隊及其成員 |
| `PATCH` | `/teams/{id}/membership` | 成員 | 是否以電子郵件接收團隊摘要 |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | 擁有者 | 透過電子郵件邀請已註冊使用者；撤回邀請 |
| `DELETE` | `/teams/{id}/members/{user}` | 擁有者，或要離開的成員本人 | 移除成員 |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | 受邀的使用者 | 寄給您電子郵件的邀請；接受或拒絕 |
| `GET` | `/billing` | 已確認電子郵件 | 目前有效的方案及訂閱 |
| `POST` | `/billing/plan` | 已確認電子郵件 | 選擇方案；需要付款時回傳 `checkout_url` |
| `POST` | `/billing/cancel`, `/billing/resume` | 已確認電子郵件 | 於計費週期結束時取消，或保留訂閱 |
| `GET` | `/billing/portal` | 已確認電子郵件 | Stripe 客戶入口網站網址 |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | 列出使用者；變更角色及方案 |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | 所有方案（含舊方案）；管理方案 |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | 管理角色權限 |
| `GET` `POST` | `/admin/imports` | `manage_imports` | 匯入執行紀錄；將匯入排入佇列（202） |
| `GET` | `/admin/digests` | `manage_imports` | 摘要各期的狀態、產生方式、改用事實摘要的原因及指標（不含內容） |
| `GET` | `/admin/trial-links` | `manage_imports` | 共用某個登錄識別碼的紀錄之間的連結；`status` = `conflict`（預設）、`auto`、`confirmed` 或 `rejected` |
| `PATCH` | `/admin/trial-links/{id}` | `manage_imports` | 確認或拒絕連結（`status`：`confirmed` 或 `rejected`）；相關紀錄會重新分組 |

搜尋篩選條件：`q`、`condition`、`keyword`、`mesh_term`、`sponsor`、`country`、`city`、`status[]`、`phase[]`、`study_type[]`、`has_results`、`start_date_from`/`_to`、`first_posted_from`/`_to`、`last_update_from`/`_to`，以及 `latitude` + `longitude` + `radius_km`。`sort` 接受 `-last_update_post_date`（預設）、`last_update_post_date`、`±start_date`、`±study_first_post_date`、`±brief_title` 及 `relevance`。

權杖缺少或無效時回傳 401；缺少權限，或存取其他使用者或團隊的資料時回傳 403；未訂閱所需方案而使用付費功能（AI 摘要、團隊工作區）時回傳 402；超過已儲存搜尋上限時回傳 403；輸入無效時回傳 422 並附上各欄位錯誤。

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 運作方式

```text
Import:  scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
           → ClinicalTrialsGovClient (pages, retries) → StudyMapper → ClinicalTrialSynchronizer (one transaction per study)
           → ClinicalTrialsImported event → MatchAlertsJob → AlertMatcher → AlertNotifier (email)
Digests: scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identity, lock, entitlement)
           → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider or factual text) → DigestDeliverer (email)
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` 包含匯入功能。每個登錄資料庫都是一個 `RegistrySource`（`app/Services/Registries/`），具備一個 `StudyRegistryClient` 及一個 `StudyPayloadMapper`；`clinicaltrials:fetch --source=<key>` 用來選擇登錄資料庫（預設為 `clinicaltrials_gov`），且每個登錄資料庫都有各自的增量游標（cursor）。對應器（mapper）是只依賴資料內容的純函式，同步器（synchronizer）則負責資料持久化。
- `app/Services/Registries/` 包含登錄資料庫清單（`SourceRegistry`）、識別碼正規化（`IdentifierParser`）及去除重複（`DuplicateLinker`，在每項研究的交易中執行）。`Fetching/RegistryFetcher` 是匯入器存取登錄資料庫的唯一途徑；`Parsing/` 包含 HTML、CSV、XLSX、PDF 文字及固定寬度表格的讀取器；`Sources/<Registry>/` 包含各個匯入器（附法律審查的來源類別、用戶端、對應器）。
- `app/Services/ClinicalTrials/Search/` 以在 `AppServiceProvider` 中註冊的小型 `TrialFilter` 類別組成搜尋。提醒會重複使用相同的條件與篩選器。
- `app/Services/ClinicalTrials/Changes/` 包含研究的追蹤事實，以及負責比較這些事實的純函式 `ChangeDetector`。
- `app/Services/Alerts/` 負責強制執行已儲存搜尋上限、比對匯入結果及寄送電子郵件。`app/Services/Billing/` 包含方案規則及 `Entitlements`；Stripe 透過 `SubscriptionGateway` 介面存取。`app/Services/Teams/` 管理團隊及邀請。
- `app/Services/Digests/` 負責產生摘要；模型透過 `AiProvider` 介面（`OpenAiCompatibleProvider`）存取。
- `app/Http/` 讓控制器保持精簡：由 Form Request 驗證、服務做決定、API Resource 決定 JSON 的結構。
- Docker 環境包含七個服務：`app`（PHP 8.6 FPM）、`queue`、`scheduler`、`mysql`（MySQL 8.4）、`redis`（Redis 8.10，作為快取儲存區，同時供速率限制器及 JWT 黑名單使用）、`nginx` 及 `node`（Node.js 26 與 Vite）。Laravel 透過 Predis 連線至 Redis（`REDIS_CLIENT=predis`），因為 phpredis 擴充套件目前還無法在 PHP 8.6 上建置。

## 開發

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- PHP 測試在專用的 MySQL 資料庫 `clinical_saas_test` 上執行，此資料庫會在首次建立 MySQL volume 時由 `docker/mysql/init/` 建立。若使用既有的 volume，請手動建立一次：`docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`。
- 匯入測試使用 `Http::fake()` 搭配 `tests/Fixtures/` 中錄製的 API 回應；計費測試使用 `Tests\Fakes\FakeSubscriptionGateway`，摘要測試使用 `Tests\Fakes\FakeAiProvider`，電子郵件也都經過模擬。測試絕不會呼叫 ClinicalTrials.gov、Stripe 或 AI 供應商。
- GitHub Actions：`.github/workflows/packages.yml` 會稽核 Composer 及 npm 的鎖定檔（即 *packages* 徽章），`.github/workflows/ci.yml` 則會在推送至 `main` 及對 `main` 發出 pull request 時、建立標籤時，以及從 Actions 分頁手動觸發時，執行 Pint、PHP 測試、型別檢查、Vue 測試及建置。分支流程為 `dev` 到 `hom` 再到 `main`。

資料庫結構僅由 `database/migrations/` 中的遷移定義。每次變更資料庫結構都要新增遷移；請勿編輯已執行過的遷移。

## 專案結構

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

## 資料來源

研究資料來自由美國國家醫學圖書館（U.S. National Library of Medicine）營運的 [ClinicalTrials.gov](https://clinicaltrials.gov/)。這些紀錄描述的是已登錄的研究，並非醫療建議。在依據任何研究細節做決定之前，請先查閱官方紀錄。其他登錄資料庫列於[其他登錄資料庫](#其他登錄資料庫)；每個登錄資料庫的資料都會附上其出處顯示。[docs/registries-evaluation.md](docs/registries-evaluation.md) 記錄了所調查的每個登錄資料庫的法律審查。

## 授權

[MIT](LICENSE-MIT)。
