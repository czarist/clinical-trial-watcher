<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">从 ClinicalTrials.gov 导入临床研究并进行搜索，当您关注的研究开放、变更或开始招募时，通过电子邮件通知您。</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a> · <a href="README.es.md">Español</a> · <a href="README.zh-CN.md">简体中文</a> · <a href="README.zh-TW.md">繁體中文</a> · <a href="README.ja.md">日本語</a> · <a href="README.ko.md">한국어</a>
</p>

<p align="center">
  <img alt="Status: early development" src="https://img.shields.io/badge/status-early_development-orange?style=flat-square&logo=data%3Aimage%2Fsvg%2Bxml%3Bbase64%2CPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI%2BPHBhdGggZmlsbD0id2hpdGUiIGQ9Ik0yMi43IDE5bC05LjEtOS4xYy45LTIuMy40LTUtMS41LTYuOS0yLTItNS0yLjQtNy40LTEuM0w5IDYgNiA5IDEuNiA0LjdDLjQgNy4xLjkgMTAuMSAyLjkgMTIuMWMxLjkgMS45IDQuNiAyLjQgNi45IDEuNWw5LjEgOS4xYy40LjQgMSAuNCAxLjQgMGwyLjMtMi4zYy41LS40LjUtMS4xLjEtMS40eiIvPjwvc3ZnPg%3D%3D">
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/ci.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/ci.yml?branch=main&label=tests&logo=githubactions&logoColor=white&style=flat-square"></a>
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/packages.yml"><img alt="Packages audit" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/packages.yml?branch=main&label=packages&logo=dependabot&logoColor=white&style=flat-square"></a>
  <img alt="PHP 8.6" src="https://img.shields.io/badge/PHP-8.6-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white">
  <img alt="TypeScript 7" src="https://img.shields.io/badge/TypeScript-7-3178C6?style=flat-square&logo=typescript&logoColor=white">
  <img alt="Node.js 26" src="https://img.shields.io/badge/Node.js-26-5FA04E?style=flat-square&logo=nodedotjs&logoColor=white">
  <img alt="npm 12.2.3" src="https://img.shields.io/badge/npm-12.2.3-CB3837?style=flat-square&logo=npm&logoColor=white">
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Redis 8.10" src="https://img.shields.io/badge/Redis-8.10-FF4438?style=flat-square&logo=redis&logoColor=white">
  <img alt="Stripe" src="https://img.shields.io/badge/billing-Stripe-635BFF?style=flat-square&logo=stripe&logoColor=white">
  <img alt="Auth: JWT" src="https://img.shields.io/badge/auth-JWT-000000?style=flat-square&logo=jsonwebtokens&logoColor=white">
  <img alt="Docker: Nginx + PHP-FPM" src="https://img.shields.io/badge/Docker-Nginx%20%2B%20PHP--FPM-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="Nginx 1.30" src="https://img.shields.io/badge/Nginx-1.30-009639?style=flat-square&logo=nginx&logoColor=white">
  <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square&logo=opensourceinitiative&logoColor=white">
</p>

<p align="center">
  <a href="#功能">功能</a> ·
  <a href="#技术栈">技术栈</a> ·
  <a href="#快速开始">快速开始</a> ·
  <a href="#api">API</a> ·
  <a href="#工作原理">工作原理</a> ·
  <a href="#开发">开发</a> ·
  <a href="ROADMAP.md">路线图</a> ·
  <a href="CHANGELOG.md">更新日志</a>
</p>

## 功能

### 从 ClinicalTrials.gov 导入

- **先导入整个注册库，之后只导入变更部分。** `php artisan clinicaltrials:fetch` 读取生产环境的 API v2（`https://clinicaltrials.gov/api/v2/studies`，校验 TLS），沿着 `nextPageToken` 读取所有分页，并记录每次运行。后续运行只请求自上次成功运行以来更新过的研究。
- **每天运行。** 调度器在 03:00（`CLINICAL_TRIALS_SCHEDULE_TIME`）将导入加入队列；由队列进程执行，同一时间只运行一个导入。
- **可安全重复执行。** 研究按 NCT 编号匹配。每项研究及其关联列表在同一个事务中保存，每个列表都会被替换为当前内容，包括空列表。保存失败的研究会被回滚并计数，运行继续进行。
- **失败会被明确报告。** 速率限制（HTTP 429）、服务器错误和连接失败会以指数退避方式重试，且重试次数有上限。无法读取注册库的运行会被记录为失败，命令以错误状态退出。
- **变更会连同变更前后的值一起记录。** 新研究被记录为新增，并成为基线。在后续导入中，会比较总体招募状态、研究机构（新增、移除或机构状态变化）、开始和完成日期（保留原始精度：只有月份的日期仍然只到月份）以及已发布的结果，并以结构化详情保存。研究机构列表按机构名称和地点比较，因此注册库中列出顺序的变化不算变更。每项变更都有稳定的指纹，因此重试或重复的导入不会将其记录或通知两次。
- **不完整的日期会被规范化**（`2024` → `2024-01-01`，`2024-06` → `2024-06-01`），完整的研究数据保存在 `raw_json` 中。

### 其他注册库

另有十二个注册库可通过 `php artisan clinicaltrials:fetch --source=<key>` 导入。只有 ClinicalTrials.gov 按每日计划运行。

| 键 | 注册库 | 读取方式 | 增量 | 商业使用 |
|---|---|---|---|---|
| `ctis` | CTIS（欧盟/欧洲经济区） | 门户 JSON API | 是（最后更新） | 是，需注明 EMA |
| `isrctn` | ISRCTN | XML API | 是（最后编辑） | 是（CC BY / CC0） |
| `euctr` | EU Clinical Trials Register | 纯文本导出 | 仅新试验 | 是，需注明该注册库 |
| `drks` | DRKS（德国） | JSON 转储；仅 CC BY 4.0 记录（2025 年起注册或更新） | 是（最后更新） | 是（CC BY 4.0） |
| `reec` | REEC（西班牙） | REST API | 最近 90 天的注册 | 是，需注明 AEMPS |
| `rebec` | ReBEC（巴西） | 网页 | 是（最后批准） | 未确认 |
| `renis` | RENIS（阿根廷） | XLSX 文件 | 否 | 是（CC BY 4.0） |
| `health_canada` | Health Canada | JSON API | 否 | 是（OGL Canada） |
| `tfda` | TFDA（台湾） | CSV 的 ZIP 包 | 否 | 是（Open Government Data License） |
| `ppb_kenya` | Pharmacy and Poisons Board（肯尼亚） | 网页 | 是（批准日期） | 未确认 |
| `eda_egypt` | Egyptian Drug Authority | PDF | 否 | 未确认 |
| `moz_ukraine` | 乌克兰卫生部命令 | PDF 附件 | 最新命令 | 未确认 |

另有八个导入器已完成并通过测试，但在运营者提供注册库所要求的条件之前保持关闭：

| 键 | 注册库 | 读取方式 | 所需条件 | 商业使用 |
|---|---|---|---|---|
| `nci_cts` | NCI Clinical Trials Search API（美国，癌症） | JSON API；试验链接到其 ClinicalTrials.gov 记录 | `REGISTRY_NCI_CTS_API_KEY` | 是，需注明 NCI |
| `mfds` | MFDS 批准的试验计划（韩国） | data.go.kr API | `REGISTRY_MFDS_SERVICE_KEY` | 是 |
| `cris` | CRIS（韩国） | data.go.kr API | `REGISTRY_CRIS_SERVICE_KEY` | 否（KOGL 第 2 类）：附带署名显示，绝不纳入 AI 摘要 |
| `anzctr` | ANZCTR（澳大利亚和新西兰） | 网页 | 书面许可，`REGISTRY_PERMISSION_ANZCTR` | 否 |
| `umin` | UMIN-CTR（日本） | 每日 CSV 转储 | 书面许可，`REGISTRY_PERMISSION_UMIN` | 否 |
| `hra` | HRA 研究摘要（英国） | 网页 | 书面许可，`REGISTRY_PERMISSION_HRA` | 否 |
| `sukl` | SÚKL（捷克） | 网站的搜索 API | 书面许可，`REGISTRY_PERMISSION_SUKL` | 否 |
| `orphanet` | Orphanet 临床试验 | Orphadata XML 转储 | 与 Orphanet-AISBL 的合同、`REGISTRY_PERMISSION_ORPHANET` 和 `REGISTRY_ORPHANET_DUMP_URL` | 否 |

由于没有可用的密钥或样本，需要密钥的 API 和 UMIN 的映射是根据其文档并使用合成 fixture 构建的；请在首次实际运行时进行核对。

- **每个导入器都通过了法律审查**，审查记录在其源类和 [docs/registries-evaluation.md](docs/registries-evaluation.md)（第 7 节）中；该文档还列出了暂时无法导入的注册库及各自所需条件（WHO ICTRP、UMIN-CTR、CRIS、ANZCTR、HRA 等）。
- **条款未确认允许商业使用的注册库，其记录会附带署名免费显示，但不纳入 AI 摘要**，直到该注册库以书面形式确认允许再利用。
- **礼貌访问。** 请求带有包含联系地址的 user agent（`REGISTRY_CONTACT`，下载和网页必填），下载和网页遵守 robots.txt 及其 Crawl-delay，每个注册库同一时间只发一个请求且间隔至少 `REGISTRY_MIN_INTERVAL_MS`，重试次数有上限。遇到登录、验证码或 HTTP 401/403 时会停止运行：请向该注册库申请许可或导出文件。绝不绕过任何限制。
- **存储页面。** 每个响应正文都会连同其 `ETag`/`Last-Modified` 一起存储（`storage/app/registry-raw/`），因此未变化的页面不会重复下载；修复解析器后，`clinicaltrials:fetch --source=<key> --from-cache` 会重新解析已存储的页面（传入与被重复的运行相同的 `--since` 或 `--full`）。
- **按发布原样保存的记录**（XML、CSV 行、网页、PDF 文本）随每项研究保存，规范化内容未变化的研究不会被重写。
- **版面变化。** 通过下载或网页读取的注册库，若完整运行解析出的记录远少于上次，或任何运行中已填写字段的比例骤降，该运行会被记录为 `partial` 并注明原因。导入绝不会删除研究。

### 重复注册

每条记录都保存其所属注册库（`source`）及其在该注册库中的标识符（`source_id`），该标识符在每个注册库内唯一，因此再次导入同一条记录会更新它。注册库列在 `config/registries.php` 中。

- **多次注册的研究只显示一次。** 每条记录的注册标识符（自身的标识符以及它引用的标识符，例如 ClinicalTrials.gov 次要 ID 中的 ISRCTN 或 EudraCT 编号）会先规范化（`ISRCTN 12345678` 与 `isrctn12345678` 视为相同）再进行比较。共享某个标识符的记录会被关联并归为一组：显示 `config/registries.php` 中排在最前的注册库的记录，其他记录作为它的其他注册保留。申办方方案编号、资助编号和标题绝不会用于合并研究。
- **存疑的匹配由人工判断。** 同一注册库中共享某个标识符的两条记录，或会包含同一注册库两条记录的分组，不会被合并：它们的关联会被标记为 `conflict`。运维人员可在 `GET /api/admin/trial-links` 列出关联并确认或拒绝；后续导入会保留该决定。共享标识符消失的关联会被删除，分组会重新计算。
- **一项研究，一次提醒。** 搜索中每项研究只列出一次；任一注册的变更都会针对该研究进行匹配；另一个注册库报告的相同消息（研究出现在该注册库中，或相同的状态）不会再次匹配或发送邮件。每期摘要中每项研究只有一个条目。
- **已有数据。** 升级后运行一次 `php artisan trials:reconcile-duplicates`，为已存储研究的标识符建立索引；导入时会对保存的每项研究自动执行此操作。

### 搜索

- 分页搜索，可按以下条件筛选：标题和摘要中的词语（MySQL 全文检索）、疾病/状况、关键词、MeSH 术语、招募状态、分期、研究类型、申办方、国家、城市、开始日期、首次发布日期和最后更新日期、是否有结果，以及与某个地点的距离。
- 研究详情页包含所有关联列表：研究机构、干预措施、研究组、结局指标、入选标准、标识符、合作方、MeSH 术语、带 PubMed 链接的出版物，以及导入过程中记录的状态变更。

### 提醒

- 提醒就是一个已保存的搜索。每次导入后，会记录与之匹配的新研究和已更新研究。
- 邮件可以在研究匹配后立即发送，也可以按每日或每周汇总发送。状态变更（例如某项研究开始招募）可以选择包含或排除。
- 基础提醒免费。所有套餐的已保存搜索数量上限相同（`SAVED_SEARCH_LIMIT`，默认 100）；在较低上限时期创建的超出部分的已保存搜索会被保留。

### AI 摘要（Pro AI 和 Team AI）

- 已保存的搜索可以开启 AI 摘要，按每日或每周发送，可使用下文列出的七种语言中的任意一种。关闭 AI 摘要后，基础提醒仍会继续运行。
- 后端挑选出报告时间窗口内与已保存搜索匹配的变更，进行计数，并根据已保存搜索的条件排序（例如，在该搜索关注的国家中新开始招募的研究机构，排名高于日期变更）。模型只负责根据已记录的事实，为每项变更撰写简短的事实性摘要。
- 每期摘要展示已保存的搜索、时间段、匹配更新的数量、最相关的变更及其匹配原因，并为每项研究列出 NCT 编号、注册库更新日期和 ClinicalTrials.gov 链接（由服务器根据经过校验的 NCT 编号生成）。缺失的事实会被明确标注为缺失；当摘要只列出最相关的更新时也会注明。
- 发送给模型的只有公开的注册库事实：不包含账户数据、电子邮件地址或用户备注。模型的回答必须是与所请求变更对应的 JSON；包含链接、其他 NCT 编号或涉及入选资格、治疗或疗效措辞的摘要会被拒绝。
- 当 AI 关闭、在有限次重试后仍失败或返回无效输出时，摘要会改用根据已记录变更生成的事实性摘要，并在该期中记录原因（`ai_disabled`、`provider_error`、`invalid_output`）。
- 摘要按变更版本、语言和提示词版本缓存，供所有订阅者复用；相关性按每个已保存搜索单独计算。
- 每期摘要都有稳定的标识（收件人或团队、已保存的搜索和时间窗口），且每个收件人对应一条投递记录，因此调度器重复运行和任务重试都不会重复发送。发送前会再次检查付费权限和团队成员资格。
- `/digests/samples` 提供两期使用虚构数据的摘要示例，无需账户即可访问。

### 团队（Team AI）

- 一位 Team AI 订阅者在一个订阅下拥有一个团队。所有者通过电子邮件邀请已注册的用户；用户只有在接受邀请后才会加入。
- 成员可以查看团队共享的已保存搜索和摘要报告，并自行选择是否通过邮件接收团队的摘要。只有所有者可以修改团队、成员及共享搜索。每个团队路由都会检查成员资格。
- 当所有者的 Team AI 订阅结束时，共享搜索和报告会被暂停；成员个人的 AI 摘要取决于其自己的套餐。

### 账户、套餐与计费

- 带邮箱确认的注册、基于 JWT 的登录、令牌刷新、退出登录、密码重置，以及个人资料和密码修改。
- 每个受保护的路由都强制执行角色和权限：`view_clinical_trials`、`create_alerts`、`manage_users`、`manage_plans`、`manage_roles` 和 `manage_imports`。
- 套餐（以美元计价）：

  | 套餐 | 价格 | 包含内容 |
  |---|---|---|
  | Free | $0 | 搜索、筛选、研究详情、变更历史、已保存的搜索和基础邮件提醒 |
  | Pro AI | 每月 $19 | 为您自己的已保存搜索提供 AI 摘要 |
  | Team AI | 每个团队每月 $99 | 共享的已保存搜索、共享的 AI 摘要，以及每期团队摘要的报告页面 |

- 新账户从 Free 开始，不提供试用。
- 付费套餐通过 Stripe 和 Laravel Cashier 实现：首次订阅使用 Stripe Checkout，在 Pro AI 和 Team AI 之间切换时按比例计费，可在周期结束时取消、恢复订阅，并可使用 Stripe 客户门户。Stripe webhook 保持账户套餐同步。取消后，付费功能会持续到已付费周期结束。
- Basic、Pro 和 Enterprise 是旧版套餐。它们已停止销售，且不提供任何 AI 功能；这些套餐上的现有订阅、价格和 Stripe 客户保持不变，其订阅者仍可取消、恢复、使用客户门户或改用当前套餐。

### Web 应用

由 Laravel 提供的 Vue 3 单页应用：搜索和研究页面、提醒及其匹配结果与 AI 摘要设置、AI 摘要报告、摘要示例、团队（成员、邀请和共享搜索）、账户与套餐，以及用户、套餐、角色和导入的管理。

### 语言

Web 应用、API 消息、电子邮件和 AI 摘要提供以下语言：英语（默认）、巴西葡萄牙语、西班牙语、简体中文、繁体中文、日语和韩语。

- Web 应用启动时，如果浏览器语言在支持之列则使用该语言（否则使用英语），并在页眉、登录页面和账户页面提供语言菜单。日期、数字和价格格式随所选语言变化。
- 每个账户都保存自己的语言（`locale`）：在注册时设置，可通过菜单或 `PUT /api/account/locale` 更改。电子邮件（确认、密码重置、提醒、团队邀请）使用收件人的语言；AI 摘要使用为其已保存搜索选择的语言。
- API 按照 `Accept-Language` 请求头（Web 应用始终会发送）的语言作答，不支持时回退到英语。响应中带有 `Content-Language`。
- 注册库数据（研究标题、摘要、疾病/状况、研究机构）保持 ClinicalTrials.gov 的原始语言；只有其周围的界面会被翻译。

后端的翻译位于 `lang/<locale>/` 和 `lang/<locale>.json`，Web 应用的翻译位于 `resources/js/i18n/locales/`。英语是源语言：当其他语言缺少某个键或占位符时，测试会失败。

> 研究数据来自公开的注册库。它不是医疗建议，不是治疗推荐，也不是关于谁可以参加某项研究的判定。

## 技术栈

版本安装于 2026-11-23。版本约束见 `composer.json` 和 `package.json`。

| 层级 | 技术 |
|---|---|
| 语言与框架 | PHP 8.6、Laravel 13.43 |
| 数据库 | MySQL 8.4 LTS（数据、全文检索、队列和会话） |
| 缓存 | Redis 8.10，通过 Predis 3.7 访问（缓存、速率限制、JWT 黑名单） |
| 认证 | JWT，使用 `tymon/jwt-auth` 2.3 |
| 计费 | Stripe，通过 Laravel Cashier 16.11 |
| HTTP 客户端 | Guzzle 8.6（Laravel HTTP 客户端），用于访问 ClinicalTrials.gov API |
| 电子邮件 | Laravel Mail 和通知（`MAIL_MAILER`；本地开发时为 `log`） |
| Web 应用 | Vue 3.5、Vue Router 5.5、Pinia 4.1、TypeScript 7.0、Public Sans（Google Fonts） |
| 前端构建 | Vite 8.3、`laravel-vite-plugin` 3.2、`@vitejs/plugin-vue` 6.0 |
| PHP 测试与质量 | PHPUnit 13.4、Mockery 1.6、Faker 1.24、Collision 8.9、Laravel Pint 1.32、Ignition 2.13（错误页面） |
| 前端测试与质量 | Vitest 5.1、Vue Test Utils 2.6、jsdom 31、`vue-tsc` 3.3（基于 `@typescript/typescript6` 6.0） |
| 工具 | Composer 2.11、Node.js 26、npm 12.2.3、Laravel Tinker 3.2 |
| 容器 | Docker Compose：PHP-FPM、队列进程、调度器、MySQL、Redis、nginx 1.30、Node.js（Vite） |
| CI | GitHub Actions |
| AI | 任何兼容 OpenAI 的 chat completions API，通过 Laravel HTTP 客户端访问（可选） |
| 外部服务 | ClinicalTrials.gov API v2、Stripe、兼容 OpenAI 的 AI 服务商、PubMed（按 PMID 生成链接） |

Laravel Breeze 2.4 和 Laravel Sail 1.71 作为 Laravel 骨架自带的开发依赖被安装，但并未使用：本应用有自己的 Vue 前端，并使用自己的 Docker Compose 文件运行。TypeScript 7 负责检查 `.ts` 文件。它的原生编译器不再提供 `vue-tsc` 所需的 JavaScript API，因此 `npm run typecheck:vue` 会在官方 TypeScript 6 包 `@typescript/typescript6` 上运行 `vue-tsc`（`scripts/vue-tsc.cjs`）来检查 `.vue` 文件；`npm run typecheck` 会同时运行两者。Redis 使用 Predis，因为 phpredis 扩展目前还无法在 PHP 8.6 上编译。`package.json` 用已修补的版本覆盖了 `glob` 和 `minimatch`，因为 `@vue/test-utils` 仍依赖存在漏洞的版本。

## 快速开始

### 环境要求

- Docker 和 Docker Compose
- 主机上的端口 `8000`（Web）、`3306`（MySQL）和 `5173`（Vite）未被占用，或在 `.env` 中设置其他端口（`APP_PORT`、`FORWARD_DB_PORT`、`VITE_PORT`）

### 启动服务

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

`.env.example` 已包含 `docker-compose.yml` 使用的数据库配置。启动时，`app` 容器会等待 MySQL 就绪，在缺少 `vendor/` 时运行 `composer install`，在 `APP_KEY` 和 `JWT_SECRET` 为空时生成它们，并运行迁移。`queue` 和 `scheduler` 容器负责执行队列任务和每日调度，`redis` 存放缓存，`node` 容器运行 Vite 开发服务器。

打开 `http://localhost:8000`，使用预置的管理员账户 `admin@admin.com` / `password` 登录。这些凭据仅用于本地开发。

`--max-pages=1` 导入第一页的 100 项研究。如果不加该参数，首次运行会读取整个注册库，耗时数小时。

如需构建 Web 应用而不是运行开发服务器：`docker-compose run --rm node npm run build`。

### 其他注册库配置

在 `.env` 中将 `REGISTRY_CONTACT` 设为注册库运营方可以联系到你的 URL 或电子邮件地址；未设置时，通过下载或网页进行的导入会拒绝运行。拉取对 `Dockerfile` 的更改后（它为 PDF 注册库安装 `poppler-utils`），用 `docker-compose up -d --build` 重新构建镜像。然后一次导入一个注册库，例如 `docker-compose exec app php artisan clinicaltrials:fetch --source=isrctn --max-pages=1`。

管理面板的 **注册库访问** 页面列出每个注册库仍缺少的内容（密钥、书面许可、付费使用确认）、联系对象以及需要遵循的步骤。需要运营方密钥的注册库从 `REGISTRY_<KEY>_SERVICE_KEY` 或 `REGISTRY_<KEY>_API_KEY` 读取密钥（见 `.env.example`）。需要书面许可的注册库在通过 `REGISTRY_PERMISSION_<KEY>=true` 记录许可之前保持关闭；请仅在注册库以书面形式授予许可后再设置。

### 计费配置

搜索和基础提醒无需 Stripe 即可使用。如需销售 Pro AI 和 Team AI：

1. 在 `.env` 中设置 `STRIPE_KEY`、`STRIPE_SECRET` 和 `STRIPE_WEBHOOK_SECRET`。
2. 在 Stripe 中创建两个以美元计价的按月循环价格：Pro AI 为 $19.00，Team AI 为 $99.00。将它们的 ID 填入 `STRIPE_PRICE_PRO_AI` 和 `STRIPE_PRICE_TEAM_AI`。
3. 运行 `docker-compose exec app php artisan billing:check-prices`。它会把这些 ID 复制到对应套餐，并检查每个价格是否处于激活状态、是否每月按套餐金额以美元收费；否则命令会失败并指出问题所在。Checkout 也会执行同样的检查，并拒绝不匹配的价格。
4. 将 Stripe webhook 指向 `https://<your-host>/stripe/webhook`，并订阅 `customer.subscription.*` 和 `invoice.*` 事件。

现有订阅：迁移 `2026_11_24_000001_add_free_and_ai_plans` 将 Basic、Pro 和 Enterprise 标记为旧版套餐，但不会更改它们的价格、Stripe 价格 ID、订阅或客户。旧版套餐的订阅者会保留原套餐，按原方式计费，且不提供 AI 功能。请保留旧版套餐的 `stripe_price_id`（在管理后台的套餐页面中），以便 webhook 继续匹配其订阅；无需将任何内容映射到新套餐。仍处于旧的 14 天注册试用期内的账户现在使用 Free。

### AI 配置

AI 摘要无需 AI 服务商也能工作：此时会使用事实性摘要。如需由模型撰写摘要，请在 `.env` 中设置：

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # any OpenAI-compatible API
AI_API_KEY=...                          # server-side only
AI_MODEL=...                            # a model name the provider accepts
```

限制项：`AI_TIMEOUT`（秒，30）、`AI_RETRIES`（2）和 `AI_RETRY_SLEEP_MS`（2000）、`AI_MAX_INPUT_CHARS`（每次请求 12000 个字符）、`AI_MAX_OUTPUT_TOKENS`（1200）以及 `AI_DIGEST_MAX_ITEMS`（每期摘要 10 项研究）。日志会记录每期摘要的状态、耗时、缓存命中和 token 数量，但从不记录 API 密钥以及模型的输入和输出。运维人员可在 `GET /api/admin/digests` 查看每期的生成方式（`ai`、`partial` 或 `fallback`）以及回退原因。

调度器在每天 07:30 将每日摘要加入队列，在每周一 07:30 将每周摘要加入队列（`php artisan digests:dispatch daily|weekly`）；`queue` 容器负责生成并发送。

### 本地验证

1. 以预置的管理员身份登录，并导入一页研究（见上文）。
2. 在“提醒”页面创建一个提醒：它在 Free 套餐上即可使用。其 AI 摘要部分会显示摘要示例和升级操作。
3. 打开 `http://localhost:8000/digests/samples` 查看两期摘要示例。
4. 在没有 Stripe 的情况下，可以在 Tinker 中手动为账户分配套餐来试用付费页面，例如在本地创建一条 `subscriptions` 记录，`stripe_status` 为 `active`，`plan_id` 为 Pro AI 或 Team AI 的 ID。然后开启 AI 摘要，再运行一次导入和 `php artisan digests:dispatch daily --end=tomorrow`（此时时间窗口包含今天）；在 `MAIL_MAILER=log` 下，摘要邮件会写入 `storage/logs/laravel.log`，报告会出现在 AI 摘要页面上。

## API

所有路由都位于 `/api` 下并返回 JSON。受保护的路由需要 `Authorization: Bearer <access_token>`。

| 方法 | 路由 | 访问权限 | 说明 |
|---|---|---|---|
| `POST` | `/auth/register` | 公开 | 创建账户（可选 `locale`，否则使用请求语言）；返回令牌（201） |
| `POST` | `/auth/login` | 公开，每分钟 5 次尝试 | 返回令牌；凭据无效时返回 HTTP 401 |
| `POST` | `/auth/refresh` | 令牌 | 新令牌 |
| `POST` | `/auth/logout` | 令牌 | 使令牌失效（204） |
| `GET` | `/auth/me` | 令牌 | 当前用户、角色、权限和计费概况 |
| `POST` | `/auth/forgot-password` | 公开 | 通过邮件发送重置链接 |
| `POST` | `/auth/reset-password` | 公开 | 使用邮件中的令牌设置新密码 |
| `GET` | `/auth/verify-email/{user}/{hash}` | 签名链接 | 确认邮箱并重定向到 Web 应用 |
| `POST` | `/auth/email/verification-notification` | 令牌 | 重新发送确认链接 |
| `PUT` | `/account/profile`, `/account/password` | 令牌 | 修改姓名和邮箱，或修改密码 |
| `PUT` | `/account/locale` | 令牌 | 修改账户语言（`locale`：`en`、`pt_BR`、`es`、`zh_CN`、`zh_TW`、`ja`、`ko`） |
| `GET` | `/trials` | `view_clinical_trials` | 搜索，每项研究一个结果，每个结果带有其 `registry`、`registry_url` 以及其条款要求的 `attribution`；筛选条件见下文（包括 `source[]`，即注册库键名），以及 `sort`、`page`、`per_page`（最多 100） |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | 研究及其所有关联列表、规范化后的 `identifiers`，以及它的其他注册（`also_registered_as`；若该记录显示在另一条记录之下，则为 `duplicate_of`） |
| `GET` `POST` | `/alerts` | `create_alerts`，已确认邮箱 | 列出或创建已保存的搜索；使用 `team_id` 处理团队共享的搜索（所有者创建，成员查看列表） |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | 所有者；团队成员可读取共享的搜索 | 读取、修改或删除已保存的搜索，包括 `ai_digest_enabled`、`ai_digest_frequency`（`daily`、`weekly`）和 `ai_digest_language`（`en`、`pt_BR`、`es`、`zh_CN`、`zh_TW`、`ja`、`ko`） |
| `GET` | `/alerts/{id}/matches` | 所有者、团队成员 | 匹配的研究 |
| `GET` | `/plans` | 公开 | Free、Pro AI 和 Team AI 及其美元价格和功能 |
| `GET` | `/digests/samples` | 公开 | 两期使用虚构数据的摘要示例 |
| `GET` | `/digests`, `/digests/{id}` | 已确认邮箱；收件人或团队成员 | 摘要各期列表（团队使用 `?team_id=`）以及包含内容的报告 |
| `GET` `POST` | `/teams` | 已确认邮箱 | 您的团队；创建团队（Team AI） |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | 成员（读取）、所有者（修改、删除） | 团队及其成员 |
| `PATCH` | `/teams/{id}/membership` | 成员 | 选择是否通过邮件接收团队摘要 |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | 所有者 | 通过邮件邀请已注册用户；撤回邀请 |
| `DELETE` | `/teams/{id}/members/{user}` | 所有者，或主动退出的成员本人 | 移除成员 |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | 被邀请的用户 | 发送到您邮箱的邀请；接受或拒绝 |
| `GET` | `/billing` | 已确认邮箱 | 当前生效的套餐和订阅 |
| `POST` | `/billing/plan` | 已确认邮箱 | 选择套餐；需要付款时返回 `checkout_url` |
| `POST` | `/billing/cancel`, `/billing/resume` | 已确认邮箱 | 在周期结束时取消，或保留订阅 |
| `GET` | `/billing/portal` | 已确认邮箱 | Stripe 客户门户 URL |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | 列出用户；修改角色和套餐 |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | 所有套餐（包括旧版）；管理套餐 |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | 管理角色权限 |
| `GET` `POST` | `/admin/imports` | `manage_imports` | 导入运行记录；将导入加入队列（202） |
| `GET` | `/admin/digests` | `manage_imports` | 摘要各期的状态、生成方式、回退原因和指标（不含内容） |
| `GET` | `/admin/registries` | `manage_imports` | 按注册库列出：使用其数据还缺少什么（`status`：`active`、`confirmation_advised`、`paid_use_unconfirmed`、`needs_key`、`needs_permission`）、联系对象、需要更改哪些设置以及每项是否已设置（绝不显示其值）、其许可证和最近一次导入；另附尚无法导入的注册库。在 Web 应用中位于 管理 > 注册库访问 |
| `GET` | `/admin/trial-links` | `manage_imports` | 共享某个注册标识符的记录之间的关联；`status` = `conflict`（默认）、`auto`、`confirmed` 或 `rejected` |
| `PATCH` | `/admin/trial-links/{id}` | `manage_imports` | 确认或拒绝一个关联（`status`：`confirmed` 或 `rejected`）；相关记录会重新分组 |

搜索筛选条件：`q`、`condition`、`keyword`、`mesh_term`、`sponsor`、`country`、`city`、`status[]`、`phase[]`、`study_type[]`、`has_results`、`start_date_from`/`_to`、`first_posted_from`/`_to`、`last_update_from`/`_to`，以及 `latitude` + `longitude` + `radius_km`。`sort` 接受 `-last_update_post_date`（默认）、`last_update_post_date`、`±start_date`、`±study_first_post_date`、`±brief_title` 和 `relevance`。

令牌缺失或无效时返回 401；缺少权限或访问其他用户或团队的记录时返回 403；在没有相应套餐的情况下使用付费功能（AI 摘要、团队工作区）时返回 402；超出已保存搜索上限时返回 403；输入无效时返回 422 并附带字段错误。

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 工作原理

```text
Import:  scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
           → ClinicalTrialsGovClient (pages, retries) → StudyMapper → ClinicalTrialSynchronizer (one transaction per study)
           → ClinicalTrialsImported event → MatchAlertsJob → AlertMatcher → AlertNotifier (email)
Digests: scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identity, lock, entitlement)
           → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider or factual text) → DigestDeliverer (email)
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` 包含导入逻辑。每个注册库都是一个 `RegistrySource`（`app/Services/Registries/`），带有一个 `StudyRegistryClient` 和一个 `StudyPayloadMapper`；`clinicaltrials:fetch --source=<key>` 用于选择注册库（默认 `clinicaltrials_gov`），每个注册库都有各自的增量游标。映射器是只依赖数据内容的纯函数，同步器负责持久化。
- `app/Services/Registries/` 包含注册库列表（`SourceRegistry`）、标识符规范化（`IdentifierParser`）和去重（`DuplicateLinker`，在每项研究的事务中运行）。`Fetching/RegistryFetcher` 是导入器访问注册库的唯一途径；`Parsing/` 包含 HTML、CSV、XLSX、PDF 文本和固定宽度表格的读取器；`Sources/<Registry>/` 包含各个导入器（附带法律审查的源类、客户端、映射器）。
- `app/Services/ClinicalTrials/Search/` 由在 `AppServiceProvider` 中注册的小型 `TrialFilter` 类组合构建搜索。提醒复用相同的条件和筛选器。
- `app/Services/ClinicalTrials/Changes/` 包含研究的跟踪事实，以及负责比较这些事实的纯函数式 `ChangeDetector`。
- `app/Services/Alerts/` 负责执行已保存搜索的数量上限、匹配导入结果并发送邮件。`app/Services/Billing/` 包含套餐规则和 `Entitlements`；通过 `SubscriptionGateway` 接口访问 Stripe。`app/Services/Teams/` 管理团队和邀请。
- `app/Services/Digests/` 负责构建摘要；通过 `AiProvider` 接口（`OpenAiCompatibleProvider`）访问模型。
- `app/Http/` 保持控制器精简：Form Request 负责校验，服务负责决策，API Resource 负责构造 JSON。
- Docker 环境包含七个服务：`app`（PHP 8.6 FPM）、`queue`、`scheduler`、`mysql`（MySQL 8.4）、`redis`（Redis 8.10，作为缓存存储，也用于速率限制器和 JWT 黑名单）、`nginx` 和 `node`（Node.js 26 和 Vite）。Laravel 通过 Predis（`REDIS_CLIENT=predis`）访问 Redis，因为 phpredis 扩展目前还无法在 PHP 8.6 上编译。

## 开发

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- PHP 测试运行在专用的 MySQL 数据库 `clinical_saas_test` 上，该数据库在首次创建 MySQL 卷时由 `docker/mysql/init/` 创建。对于已有的卷，请手动创建一次：`docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`。
- 导入测试使用 `Http::fake()` 和 `tests/Fixtures/` 中录制的 API 响应；计费测试使用 `Tests\Fakes\FakeSubscriptionGateway`，摘要测试使用 `Tests\Fakes\FakeAiProvider`，邮件均为模拟发送。测试从不调用 ClinicalTrials.gov、Stripe 或 AI 服务商。
- GitHub Actions：`.github/workflows/packages.yml` 审计 Composer 和 npm 的锁文件（即 *packages* 徽章），`.github/workflows/ci.yml` 在推送到 `main` 及向其发起拉取请求时、在打标签时，以及从 Actions 标签页手动触发时，运行 Pint、PHP 测试、类型检查、Vue 测试和构建。分支流转顺序为 `dev` → `hom` → `main`。

数据库结构只由 `database/migrations/` 中的迁移定义。每次结构变更都要新增一个迁移；不要修改已经运行过的迁移。

## 项目结构

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

## 数据来源

研究数据来自 [ClinicalTrials.gov](https://clinicaltrials.gov/)，由美国国家医学图书馆（U.S. National Library of Medicine）运营。这些记录描述的是已注册的研究，不构成医疗建议。在依赖任何研究细节之前，请先核对官方记录。其他注册库列于[其他注册库](#其他注册库)；每个注册库的数据都会附带其署名显示。[docs/registries-evaluation.md](docs/registries-evaluation.md) 记录了所调研的每个注册库的法律审查。

## 许可证

[MIT](LICENSE-MIT)。
