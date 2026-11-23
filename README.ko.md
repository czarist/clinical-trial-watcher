<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">ClinicalTrials.gov에서 임상 연구를 가져와 검색하고, 팔로우하는 연구가 등록되거나 변경되거나 모집을 시작하면 이메일로 알림을 받으세요.</h3>

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
  <a href="#기능">기능</a> ·
  <a href="#기술-스택">기술 스택</a> ·
  <a href="#빠른-시작">빠른 시작</a> ·
  <a href="#api">API</a> ·
  <a href="#동작-방식">동작 방식</a> ·
  <a href="#개발">개발</a> ·
  <a href="ROADMAP.md">로드맵</a> ·
  <a href="CHANGELOG.md">변경 내역</a>
</p>

## 기능

### ClinicalTrials.gov에서 가져오기

- **처음에는 레지스트리 전체를, 그다음에는 변경분만.** `php artisan clinicaltrials:fetch`는 운영 API v2(`https://clinicaltrials.gov/api/v2/studies`, TLS 검증)를 읽고, `nextPageToken`을 따라 모든 페이지를 순회하며, 실행할 때마다 기록을 남깁니다. 이후 실행에서는 마지막으로 성공한 실행 이후 업데이트된 연구만 요청합니다.
- **매일 실행.** 스케줄러가 03:00(`CLINICAL_TRIALS_SCHEDULE_TIME`)에 가져오기를 대기열에 넣고, 대기열 작업자가 한 번에 하나씩 실행합니다.
- **반복 실행해도 안전.** 연구는 NCT 번호로 식별합니다. 각 연구와 관련 목록은 하나의 트랜잭션으로 저장되며, 모든 목록은 빈 목록을 포함해 현재 목록으로 교체됩니다. 실패한 연구는 롤백되고 집계되며, 실행은 계속됩니다.
- **실패를 명시적으로 처리.** 요청 한도 초과(HTTP 429), 서버 오류, 연결 실패는 지수 백오프로 제한된 횟수만큼 재시도합니다. 레지스트리를 읽지 못한 실행은 실패로 기록되고 명령은 오류와 함께 종료됩니다.
- **변경 전후 값으로 변경 사항을 추적.** 새 연구는 신규로 기록되어 기준점이 됩니다. 이후 가져오기에서는 전체 모집 상태, 연구 기관(추가, 제거, 기관별 상태 변경), 시작일과 완료일(원래 정밀도 유지: 월까지만 있는 날짜는 월 단위로 유지), 게시된 결과를 비교해 구조화된 세부 정보로 저장합니다. 연구 기관 목록은 기관과 위치로 비교하므로 레지스트리의 나열 순서가 바뀐 것은 변경으로 보지 않습니다. 각 변경에는 고정된 지문(fingerprint)이 있어, 재시도하거나 반복한 가져오기에서도 같은 변경이 두 번 기록되거나 알림으로 발송되지 않습니다.
- **부분 날짜는 정규화되며**(`2024` → `2024-01-01`, `2024-06` → `2024-06-01`), 연구 페이로드 전체는 `raw_json`에 보관됩니다.

### 기타 레지스트리

12개 레지스트리를 추가로 `php artisan clinicaltrials:fetch --source=<key>`로 가져올 수 있습니다. 매일 일정에 따라 실행되는 것은 ClinicalTrials.gov뿐입니다.

| 키 | 레지스트리 | 읽기 방식 | 증분 | 상업적 이용 |
|---|---|---|---|---|
| `ctis` | CTIS(EU/EEA) | 포털 JSON API | 예(최종 업데이트) | 예, EMA 출처 표시 |
| `isrctn` | ISRCTN | XML API | 예(최종 수정) | 예(CC BY / CC0) |
| `euctr` | EU Clinical Trials Register | 일반 텍스트 내보내기 | 새 임상시험만 | 예, 레지스트리 출처 표시 |
| `drks` | DRKS(독일) | JSON 덤프, CC BY 4.0 레코드만(2025년부터 등록 또는 업데이트) | 예(최종 업데이트) | 예(CC BY 4.0) |
| `reec` | REEC(스페인) | REST API | 최근 90일간의 등록 | 예, AEMPS 인용 |
| `rebec` | ReBEC(브라질) | 웹 페이지 | 예(최종 승인) | 확인되지 않음 |
| `renis` | RENIS(아르헨티나) | XLSX 파일 | 아니요 | 예(CC BY 4.0) |
| `health_canada` | Health Canada | JSON API | 아니요 | 예(OGL Canada) |
| `tfda` | TFDA(대만) | CSV ZIP | 아니요 | 예(Open Government Data License) |
| `ppb_kenya` | Pharmacy and Poisons Board(케냐) | 웹 페이지 | 예(승인일) | 확인되지 않음 |
| `eda_egypt` | Egyptian Drug Authority | PDF | 아니요 | 확인되지 않음 |
| `moz_ukraine` | 우크라이나 보건부 명령 | PDF 부속서 | 최신 명령 | 확인되지 않음 |

8개의 가져오기 도구가 추가로 구현되고 테스트되었지만, 운영자가 레지스트리가 요구하는 것을 제공할 때까지는 꺼져 있습니다.

| 키 | 레지스트리 | 읽기 방식 | 필요 사항 | 상업적 이용 |
|---|---|---|---|---|
| `nci_cts` | NCI Clinical Trials Search API (미국, 암) | JSON API. 시험은 해당 ClinicalTrials.gov 레코드에 연결됨 | `REGISTRY_NCI_CTS_API_KEY` | 가능, NCI 출처 표시 |
| `mfds` | 식약처(MFDS) 승인 임상시험 계획 (대한민국) | data.go.kr API | `REGISTRY_MFDS_SERVICE_KEY` | 가능 |
| `cris` | CRIS (대한민국) | data.go.kr API | `REGISTRY_CRIS_SERVICE_KEY` | 불가 (공공누리 제2유형): 출처를 밝혀 표시하며 AI 다이제스트에는 포함하지 않음 |
| `anzctr` | ANZCTR (호주 및 뉴질랜드) | 웹 페이지 | 서면 허가, `REGISTRY_PERMISSION_ANZCTR` | 불가 |
| `umin` | UMIN-CTR (일본) | 일일 CSV 덤프 | 서면 허가, `REGISTRY_PERMISSION_UMIN` | 불가 |
| `hra` | HRA 연구 요약 (영국) | 웹 페이지 | 서면 허가, `REGISTRY_PERMISSION_HRA` | 불가 |
| `sukl` | SÚKL (체코) | 웹사이트의 검색 API | 서면 허가, `REGISTRY_PERMISSION_SUKL` | 불가 |
| `orphanet` | Orphanet 임상시험 | Orphadata XML 덤프 | Orphanet-AISBL과의 계약, `REGISTRY_PERMISSION_ORPHANET` 및 `REGISTRY_ORPHANET_DUMP_URL` | 불가 |

키로 보호되는 API와 UMIN의 매핑은 사용할 수 있는 키나 샘플이 없어 문서를 바탕으로 합성 fixture로 만들었습니다. 첫 실제 실행에서 확인하세요.

- **모든 가져오기 도구는 법률 검토를 통과했습니다.** 검토 내용은 각 소스 클래스와 [docs/registries-evaluation.md](docs/registries-evaluation.md)(7절)에 기록되어 있으며, 이 문서에는 아직 가져올 수 없는 레지스트리와 각각에 필요한 사항(WHO ICTRP, UMIN-CTR, CRIS, ANZCTR, HRA 등)도 나와 있습니다.
- **약관에서 상업적 이용이 확인되지 않는 레지스트리의 레코드는 출처를 밝혀 무료로 표시하지만 AI 다이제스트에서는 제외합니다.** 레지스트리가 재사용을 서면으로 확인할 때까지 이렇게 처리합니다.
- **예의 바른 접근.** 요청에는 연락처 주소가 포함된 user agent(`REGISTRY_CONTACT`, 다운로드와 웹 페이지에 필수)를 붙이고, 다운로드와 웹 페이지에서는 robots.txt와 그 Crawl-delay를 따르며, 레지스트리별로 한 번에 하나씩 최소 `REGISTRY_MIN_INTERVAL_MS` 간격으로 보내고, 제한된 횟수만큼 재시도합니다. 로그인, 캡차 또는 HTTP 401/403을 만나면 실행을 중단합니다. 이 경우 레지스트리에 허가나 내보내기 파일을 요청하세요. 어떤 제한도 우회하지 않습니다.
- **저장된 페이지.** 모든 응답 본문은 `ETag`/`Last-Modified`와 함께 저장되므로(`storage/app/registry-raw/`) 바뀌지 않은 페이지는 다시 내려받지 않으며, 파서를 수정한 뒤에는 `clinicaltrials:fetch --source=<key> --from-cache`로 저장된 페이지를 다시 파싱합니다(반복하려는 실행과 같은 `--since` 또는 `--full`을 지정하세요).
- **게시된 그대로의 레코드**(XML, CSV 행, 웹 페이지, PDF 텍스트)는 각 연구와 함께 보관되며, 정규화된 내용이 바뀌지 않은 연구는 다시 쓰지 않습니다.
- **레이아웃 변경.** 다운로드나 웹 페이지로 읽는 레지스트리에서 전체 실행이 이전보다 훨씬 적은 레코드를 파싱하거나, 어떤 실행이든 채워진 필드의 비율이 급격히 떨어지면 그 실행은 사유와 함께 `partial`로 기록됩니다. 가져오기는 연구를 절대 삭제하지 않습니다.

### 중복 등록

각 레코드는 해당 레지스트리(`source`)와 그 레지스트리에서의 식별자(`source_id`, 레지스트리별로 고유)를 보관하므로, 같은 레코드를 다시 가져오면 업데이트됩니다. 레지스트리 목록은 `config/registries.php`에 있습니다.

- **여러 번 등록된 연구는 한 번만 표시.** 각 레코드의 레지스트리 식별자(자신의 식별자와, ClinicalTrials.gov 보조 ID에 있는 ISRCTN 또는 EudraCT 번호처럼 레코드가 인용하는 식별자)는 정규화한 뒤(`ISRCTN 12345678`과 `isrctn12345678`은 같음) 비교합니다. 식별자를 공유하는 레코드는 연결되어 하나의 그룹으로 묶입니다. `config/registries.php`에서 가장 먼저 나열된 레지스트리의 레코드가 표시되고, 나머지는 그 연구의 다른 등록으로 보관됩니다. 후원 기관의 프로토콜 번호, 연구비 번호, 제목은 연구를 병합하는 데 절대 사용하지 않습니다.
- **불확실한 일치는 사람이 판단.** 같은 레지스트리의 두 레코드가 식별자를 공유하거나, 한 레지스트리의 레코드 두 개를 포함하게 되는 그룹은 병합하지 않고 해당 연결을 `conflict`로 표시합니다. 운영자는 `GET /api/admin/trial-links`에서 연결 목록을 보고 확정하거나 거부하며, 이 결정은 이후 가져오기에서도 유지됩니다. 공유하던 식별자가 사라진 연결은 제거되고 그룹이 다시 계산됩니다.
- **연구 하나에 알림 하나.** 검색에서는 각 연구가 한 번만 나열되고, 어느 등록의 변경이든 해당 연구를 기준으로 일치 여부를 확인하며, 두 번째 레지스트리가 보고한 같은 소식(그 레지스트리에 연구가 등장하거나 같은 상태)은 다시 일치하거나 이메일로 발송되지 않습니다. 다이제스트에는 연구당 항목이 하나입니다.
- **기존 데이터.** 업그레이드 후 `php artisan trials:reconcile-duplicates`를 한 번 실행해 이미 저장된 연구의 식별자를 색인하세요. 가져오기는 저장하는 모든 연구에 대해 이 작업을 수행합니다.

### 검색

- 페이지 단위 검색과 다양한 필터: 제목과 요약의 단어(MySQL 전문 검색), 질환, 키워드, MeSH 용어, 모집 상태, 임상 단계, 연구 유형, 후원 기관, 국가, 도시, 시작일·최초 게시일·최종 업데이트일, 결과 유무, 특정 지점으로부터의 거리.
- 모든 관련 목록을 보여 주는 연구 페이지: 연구 기관, 중재, 군(arm), 결과 변수, 참여 기준, 식별자, 공동 연구 기관, MeSH 용어, PubMed 링크가 포함된 출판물, 가져오기에서 기록한 상태 변경.

### 알림

- 알림은 저장된 검색입니다. 가져오기가 끝날 때마다 이와 일치하는 새 연구와 업데이트된 연구가 기록됩니다.
- 연구가 일치하는 즉시 또는 일일·주간 요약으로 이메일을 보냅니다. 연구의 모집 시작과 같은 상태 변경을 포함할지 선택할 수 있습니다.
- 기본 알림은 무료입니다. 모든 요금제의 저장된 검색 한도는 같으며(`SAVED_SEARCH_LIMIT`, 기본값 100), 예전의 더 낮은 한도를 넘어 만든 저장된 검색도 그대로 유지됩니다.

### AI 다이제스트 (Pro AI 및 Team AI)

- 저장된 검색에 일일 또는 주간 AI 다이제스트를 설정할 수 있으며, 아래 7개 언어 중 어느 것으로든 받을 수 있습니다. 다이제스트를 꺼도 기본 알림은 계속 동작합니다.
- 백엔드는 보고 기간 동안 저장된 검색과 일치한 변경을 골라 개수를 세고, 저장된 검색의 조건에 따라 순위를 매깁니다(예를 들어 검색에서 모니터링하는 국가에 새로 모집을 시작한 연구 기관은 날짜 변경보다 높은 순위를 받습니다). 모델은 기록된 사실을 바탕으로 각 변경에 대한 짧은 사실 요약의 문장만 작성합니다.
- 각 다이제스트에는 저장된 검색, 기간, 일치한 업데이트 수, 가장 관련성 높은 변경과 일치 이유, 그리고 연구마다 NCT 번호, 레지스트리 업데이트 날짜, ClinicalTrials.gov 링크(검증된 NCT 번호로 서버가 생성)가 표시됩니다. 누락된 사실은 누락되었다고 명시하며, 가장 관련성 높은 업데이트만 나열한 경우에는 그 사실을 밝힙니다.
- 모델에는 공개 레지스트리 정보만 보냅니다. 계정 데이터, 이메일 주소, 사용자 메모는 보내지 않습니다. 모델의 응답은 요청한 변경에 대응하는 JSON이어야 하며, 링크나 다른 NCT 번호가 포함되거나 참여 자격, 치료, 효능에 관한 표현이 담긴 요약은 거부됩니다.
- AI가 꺼져 있거나, 제한된 재시도 후에도 실패하거나, 유효하지 않은 출력을 반환하면 다이제스트는 기록된 변경으로 만든 사실 요약을 사용하고, 해당 회차에 그 이유(`ai_disabled`, `provider_error`, `invalid_output`)를 기록합니다.
- 요약은 변경 버전, 언어, 프롬프트 버전별로 캐시되어 모든 구독자가 재사용하며, 관련성은 저장된 검색마다 계산합니다.
- 각 회차는 고정된 식별 정보(수신자 또는 팀, 저장된 검색, 기간)와 수신자별 발송 행을 가지므로, 스케줄러를 다시 실행하거나 작업을 재시도해도 두 번 발송되지 않습니다. 발송 직전에 유료 이용 권한과 팀 구성원 자격을 다시 확인합니다.
- 합성 데이터로 만든 샘플 회차 두 개를 계정 없이 `/digests/samples`에서 볼 수 있습니다.

### 팀 (Team AI)

- Team AI 구독자는 하나의 구독으로 팀 하나를 소유합니다. 소유자는 가입한 사용자를 이메일로 초대하며, 초대받은 사람은 수락해야만 가입됩니다.
- 구성원은 팀의 공유 저장 검색과 다이제스트 보고서를 보고, 팀 다이제스트를 이메일로 받을지 선택합니다. 팀, 구성원, 공유 검색은 소유자만 변경할 수 있습니다. 모든 팀 경로에서 구성원 자격을 확인합니다.
- 소유자의 Team AI 구독이 종료되면 공유 검색과 보고서는 일시 중지됩니다. 구성원 개인의 AI 다이제스트는 각자의 요금제에 따릅니다.

### 계정, 요금제, 결제

- 이메일 확인을 거치는 회원 가입, JWT 로그인, 토큰 갱신, 로그아웃, 비밀번호 재설정, 프로필 및 비밀번호 변경.
- 모든 보호된 경로에 적용되는 역할과 권한: `view_clinical_trials`, `create_alerts`, `manage_users`, `manage_plans`, `manage_roles`, `manage_imports`.
- 요금제(미국 달러):

  | 요금제 | 가격 | 포함 기능 |
  |---|---|---|
  | Free | $0 | 검색, 필터, 연구 상세 정보, 변경 이력, 저장된 검색, 기본 이메일 알림 |
  | Pro AI | 월 $19 | 본인의 저장된 검색에 대한 AI 다이제스트 |
  | Team AI | 팀당 월 $99 | 공유 저장 검색, 공유 AI 다이제스트, 팀 다이제스트별 보고서 페이지 |

- 새 계정은 체험 기간 없이 Free로 시작합니다.
- Stripe와 Laravel Cashier를 통한 유료 요금제: 첫 구독은 Stripe Checkout, Pro AI와 Team AI 간 전환은 일할 계산(proration), 기간 종료 시 취소, 구독 재개, Stripe 고객 포털을 지원합니다. Stripe 웹훅이 계정의 요금제를 동기화합니다. 취소한 뒤에도 결제한 기간이 끝날 때까지 유료 기능을 사용할 수 있습니다.
- Basic, Pro, Enterprise는 레거시 요금제입니다. 더 이상 판매하지 않으며 AI 기능도 제공하지 않습니다. 이 요금제의 기존 구독, 가격, Stripe 고객은 그대로 유지되며, 구독자는 계속해서 취소, 재개, 고객 포털 이용, 현재 요금제로의 전환을 할 수 있습니다.

### 웹 앱

Laravel이 제공하는 Vue 3 단일 페이지 앱입니다. 검색 및 연구 페이지, 일치 항목과 AI 다이제스트 설정이 있는 알림, AI 다이제스트 보고서, 샘플 다이제스트, 팀(구성원, 초대, 공유 검색), 계정 및 요금제, 그리고 사용자·요금제·역할·가져오기 관리를 포함합니다.

### 언어

웹 앱, API 메시지, 이메일, AI 다이제스트는 영어(기본값), 브라질 포르투갈어, 스페인어, 중국어 간체, 중국어 번체, 일본어, 한국어로 제공됩니다.

- 웹 앱은 브라우저 언어가 지원되면 그 언어로 시작하고(그렇지 않으면 영어), 헤더와 로그인 페이지, 계정 페이지에 언어 메뉴가 있습니다. 날짜, 숫자, 가격은 선택한 언어의 형식을 따릅니다.
- 각 계정은 언어(`locale`)를 저장합니다. 가입 시 설정되며 메뉴나 `PUT /api/account/locale`로 변경할 수 있습니다. 이메일(가입 확인, 비밀번호 재설정, 알림, 팀 초대)은 수신자의 언어를 사용하고, AI 다이제스트는 해당 저장된 검색에 지정한 언어를 사용합니다.
- API는 웹 앱이 항상 보내는 `Accept-Language` 헤더의 언어로 응답하며, 없으면 영어로 응답합니다. 응답에는 `Content-Language`가 포함됩니다.
- 레지스트리 데이터(연구 제목, 요약, 질환, 연구 기관)는 ClinicalTrials.gov의 원래 언어로 유지되며, 이를 둘러싼 인터페이스만 번역됩니다.

번역은 백엔드의 경우 `lang/<locale>/`와 `lang/<locale>.json`에, 웹 앱의 경우 `resources/js/i18n/locales/`에 있습니다. 영어가 원본이며, 다른 언어에 키나 플레이스홀더가 빠져 있으면 테스트가 실패합니다.

> 연구 데이터는 공개 레지스트리에서 가져온 것입니다. 의학적 조언이나 치료 권고가 아니며, 누가 연구에 참여할 수 있는지에 대한 판단도 아닙니다.

## 기술 스택

2026-11-23 기준으로 설치된 버전입니다. 버전 제약은 `composer.json`과 `package.json`에 있습니다.

| 계층 | 기술 |
|---|---|
| 언어 및 프레임워크 | PHP 8.6, Laravel 13.43 |
| 데이터베이스 | MySQL 8.4 LTS (데이터, 전문 검색, 대기열, 세션) |
| 캐시 | Redis 8.10, Predis 3.7 사용 (캐시, 요청 한도 제한, JWT 블랙리스트) |
| 인증 | `tymon/jwt-auth` 2.3 기반 JWT |
| 결제 | Laravel Cashier 16.11을 통한 Stripe |
| HTTP 클라이언트 | ClinicalTrials.gov API용 Guzzle 8.6 (Laravel HTTP 클라이언트) |
| 이메일 | Laravel Mail 및 알림 (`MAIL_MAILER`, 로컬 개발에서는 `log`) |
| 웹 앱 | Vue 3.5, Vue Router 5.5, Pinia 4.1, TypeScript 7.0, Public Sans (Google Fonts) |
| 프런트엔드 빌드 | Vite 8.3, `laravel-vite-plugin` 3.2, `@vitejs/plugin-vue` 6.0 |
| PHP 테스트 및 품질 | PHPUnit 13.4, Mockery 1.6, Faker 1.24, Collision 8.9, Laravel Pint 1.32, Ignition 2.13 (오류 페이지) |
| 프런트엔드 테스트 및 품질 | Vitest 5.1, Vue Test Utils 2.6, jsdom 31, `vue-tsc` 3.3 (`@typescript/typescript6` 6.0 기반) |
| 도구 | Composer 2.11, Node.js 26, npm 12.2.3, Laravel Tinker 3.2 |
| 컨테이너 | Docker Compose: PHP-FPM, 대기열 작업자, 스케줄러, MySQL, Redis, nginx 1.30, Node.js (Vite) |
| CI | GitHub Actions |
| AI | Laravel HTTP 클라이언트를 통한 OpenAI 호환 채팅 완성(chat completions) API (선택 사항) |
| 외부 서비스 | ClinicalTrials.gov API v2, Stripe, OpenAI 호환 AI 제공자, PubMed (PMID 링크) |

Laravel Breeze 2.4와 Laravel Sail 1.71은 Laravel 스켈레톤에서 개발 의존성으로 설치되어 있지만 사용하지 않습니다. 이 앱은 자체 Vue 프런트엔드를 갖고 있으며 자체 Docker Compose 파일로 실행됩니다. TypeScript 7이 `.ts` 파일을 검사합니다. TypeScript 7의 네이티브 컴파일러에는 `vue-tsc`가 필요로 하는 JavaScript API가 더 이상 없으므로, `npm run typecheck:vue`는 공식 TypeScript 6 패키지인 `@typescript/typescript6`(`scripts/vue-tsc.cjs`) 위에서 `vue-tsc`를 실행해 `.vue` 파일을 검사하며, `npm run typecheck`는 두 검사를 모두 실행합니다. phpredis 확장이 아직 PHP 8.6에서 빌드되지 않으므로 Redis는 Predis를 사용합니다. `@vue/test-utils`가 아직 취약한 버전에 의존하기 때문에 `package.json`에서 `glob`과 `minimatch`를 패치된 버전으로 재정의합니다.

## 빠른 시작

### 요구 사항

- Docker 및 Docker Compose
- 호스트에서 포트 `8000`(웹), `3306`(MySQL), `5173`(Vite)이 비어 있거나, `.env`에 다른 포트를 설정(`APP_PORT`, `FORWARD_DB_PORT`, `VITE_PORT`)

### 스택 실행

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

`.env.example`에는 `docker-compose.yml`에서 사용하는 데이터베이스 값이 이미 들어 있습니다. 시작 시 `app` 컨테이너는 MySQL을 기다리고, `vendor/`가 없으면 `composer install`을 실행하며, `APP_KEY`와 `JWT_SECRET`이 비어 있으면 생성하고, 마이그레이션을 실행합니다. `queue`와 `scheduler` 컨테이너는 대기열 작업과 일일 스케줄을 실행하고, `redis`는 캐시를 담당하며, `node` 컨테이너는 Vite 개발 서버를 실행합니다.

`http://localhost:8000`을 열고 시드된 관리자 계정 `admin@admin.com` / `password`로 로그인하세요. 이 자격 증명은 로컬 개발 전용입니다.

`--max-pages=1`은 첫 페이지의 연구 100건을 가져옵니다. 이 옵션이 없으면 첫 실행에서 레지스트리 전체를 읽으며, 몇 시간이 걸립니다.

개발 서버 대신 웹 앱을 빌드하려면 `docker-compose run --rm node npm run build`를 실행하세요.

### 기타 레지스트리 설정

`.env`의 `REGISTRY_CONTACT`에 레지스트리 운영자가 연락할 수 있는 URL 또는 이메일 주소를 설정하세요. 이 값이 없으면 다운로드나 웹 페이지를 통한 가져오기는 실행되지 않습니다. `Dockerfile` 변경 사항을 가져온 뒤에는(PDF 레지스트리용 `poppler-utils`를 설치합니다) `docker-compose up -d --build`로 이미지를 다시 빌드하세요. 그런 다음 레지스트리를 하나씩 가져오세요. 예: `docker-compose exec app php artisan clinicaltrials:fetch --source=isrctn --max-pages=1`.

관리 패널의 **레지스트리 접근** 페이지는 레지스트리마다 아직 부족한 것(키, 서면 허가, 유료 사용 확인), 연락처, 따라야 할 절차를 보여 줍니다. 운영자 키가 필요한 레지스트리는 `REGISTRY_<KEY>_SERVICE_KEY` 또는 `REGISTRY_<KEY>_API_KEY`에서 키를 읽습니다(`.env.example` 참고). 서면 허가가 필요한 레지스트리는 `REGISTRY_PERMISSION_<KEY>=true`로 허가가 기록될 때까지 꺼져 있습니다. 레지스트리가 서면으로 허가한 뒤에만 설정하세요.

### 결제 설정

검색과 기본 알림은 Stripe 없이도 동작합니다. Pro AI와 Team AI를 판매하려면 다음을 수행하세요.

1. `.env`에 `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`을 설정합니다.
2. Stripe에서 USD 기준 월간 반복 가격 두 개를 만듭니다. Pro AI는 $19.00, Team AI는 $99.00입니다. 각 ID를 `STRIPE_PRICE_PRO_AI`와 `STRIPE_PRICE_TEAM_AI`에 넣습니다.
3. `docker-compose exec app php artisan billing:check-prices`를 실행합니다. 이 명령은 ID를 요금제에 복사하고, 각 가격이 활성 상태이며 매월 요금제 금액을 USD로 청구하는지 확인합니다. 그렇지 않으면 실패하며 문제를 알려 줍니다. Checkout도 같은 검사를 수행하며 일치하지 않는 가격은 거부합니다.
4. `customer.subscription.*` 및 `invoice.*` 이벤트로 Stripe 웹훅을 `https://<your-host>/stripe/webhook`에 연결합니다.

기존 구독: 마이그레이션 `2026_11_24_000001_add_free_and_ai_plans`는 Basic, Pro, Enterprise의 가격, Stripe 가격 ID, 구독, 고객을 변경하지 않고 레거시로 표시합니다. 레거시 요금제 구독자는 해당 요금제를 유지하며, 이전과 같이 청구되고 AI 기능은 없습니다. 웹훅이 계속 해당 구독과 일치하도록 레거시 요금제의 `stripe_price_id`를 설정된 상태로 두세요(관리의 요금제 페이지). 새 요금제로 매핑할 것은 없습니다. 예전 14일 가입 체험 기간 중인 계정은 이제 Free를 사용합니다.

### AI 설정

AI 다이제스트는 제공자 없이도 동작하며, 이 경우 사실 요약을 사용합니다. 모델이 요약을 작성하게 하려면 `.env`에 다음을 설정하세요.

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # any OpenAI-compatible API
AI_API_KEY=...                          # server-side only
AI_MODEL=...                            # a model name the provider accepts
```

제한값: `AI_TIMEOUT`(초, 30), `AI_RETRIES`(2), `AI_RETRY_SLEEP_MS`(2000), `AI_MAX_INPUT_CHARS`(요청당 12000자), `AI_MAX_OUTPUT_TOKENS`(1200), `AI_DIGEST_MAX_ITEMS`(다이제스트당 연구 10건). 로그에는 다이제스트별 상태, 소요 시간, 캐시 적중, 토큰 수가 기록되며, API 키나 모델의 입력과 출력은 절대 기록되지 않습니다. 운영자는 `GET /api/admin/digests`에서 회차별 생성 방식(`ai`, `partial`, `fallback`)과 대체 사유를 확인할 수 있습니다.

스케줄러는 일일 다이제스트를 매일 07:30에, 주간 다이제스트를 매주 월요일 07:30에 대기열에 넣으며(`php artisan digests:dispatch daily|weekly`), `queue` 컨테이너가 이를 생성하고 발송합니다.

### 로컬에서 확인하기

1. 시드된 관리자로 로그인하고 연구 한 페이지를 가져옵니다(위 참고).
2. 알림 페이지에서 알림을 만듭니다. Free에서도 동작합니다. AI 다이제스트 섹션에는 샘플 다이제스트와 업그레이드 버튼이 표시됩니다.
3. `http://localhost:8000/digests/samples`를 열어 샘플 회차 두 개를 확인합니다.
4. Stripe 없이 유료 화면을 시험하려면 Tinker에서 계정에 요금제를 직접 부여합니다. 예를 들어 `stripe_status`가 `active`이고 `plan_id`가 Pro AI 또는 Team AI인 로컬 `subscriptions` 행을 만듭니다. 그런 다음 AI 다이제스트를 켜고, 가져오기를 한 번 더 실행한 뒤 `php artisan digests:dispatch daily --end=tomorrow`를 실행합니다(이렇게 하면 기간에 오늘이 포함됩니다). `MAIL_MAILER=log`이면 다이제스트 이메일은 `storage/logs/laravel.log`에 기록되고, 보고서는 AI 다이제스트 페이지에 표시됩니다.

## API

모든 경로는 `/api` 아래에 있으며 JSON을 반환합니다. 보호된 경로에는 `Authorization: Bearer <access_token>`이 필요합니다.

| 메서드 | 경로 | 접근 권한 | 설명 |
|---|---|---|---|
| `POST` | `/auth/register` | 공개 | 계정 생성(선택 사항인 `locale`, 없으면 요청 언어). 토큰 반환(201) |
| `POST` | `/auth/login` | 공개, 분당 5회 시도 | 토큰, 잘못된 자격 증명이면 HTTP 401 |
| `POST` | `/auth/refresh` | 토큰 | 새 토큰 |
| `POST` | `/auth/logout` | 토큰 | 토큰 무효화(204) |
| `GET` | `/auth/me` | 토큰 | 현재 사용자, 역할, 권한, 결제 요약 |
| `POST` | `/auth/forgot-password` | 공개 | 재설정 링크를 이메일로 발송 |
| `POST` | `/auth/reset-password` | 공개 | 이메일로 받은 토큰으로 새 비밀번호 설정 |
| `GET` | `/auth/verify-email/{user}/{hash}` | 서명된 링크 | 이메일을 확인하고 웹 앱으로 리디렉션 |
| `POST` | `/auth/email/verification-notification` | 토큰 | 확인 링크 재발송 |
| `PUT` | `/account/profile`, `/account/password` | 토큰 | 이름과 이메일 또는 비밀번호 변경 |
| `PUT` | `/account/locale` | 토큰 | 계정 언어 변경(`locale`: `en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/trials` | `view_clinical_trials` | 검색(연구당 결과 하나, 각 결과에 `registry`, `registry_url`, 약관이 요구하는 `attribution` 포함). 아래 필터(레지스트리 키인 `source[]` 포함), `sort`, `page`, `per_page`(최대 100) |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | 모든 관련 목록, 정규화된 `identifiers`, 다른 등록(`also_registered_as`, 또는 다른 레코드 아래에 표시되는 레코드의 경우 `duplicate_of`)을 포함한 연구 |
| `GET` `POST` | `/alerts` | `create_alerts`, 이메일 확인 완료 | 저장된 검색 목록 조회 또는 생성. 팀 공유 검색은 `team_id` 사용(소유자는 생성, 구성원은 목록 조회) |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | 소유자, 팀 구성원은 공유 검색 조회 가능 | 저장된 검색 조회, 변경, 삭제. `ai_digest_enabled`, `ai_digest_frequency`(`daily`, `weekly`), `ai_digest_language`(`en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) 포함 |
| `GET` | `/alerts/{id}/matches` | 소유자, 팀 구성원 | 일치한 연구 |
| `GET` | `/plans` | 공개 | USD 가격과 기능이 포함된 Free, Pro AI, Team AI |
| `GET` | `/digests/samples` | 공개 | 합성 데이터로 만든 샘플 다이제스트 회차 두 개 |
| `GET` | `/digests`, `/digests/{id}` | 이메일 확인 완료, 수신자 또는 팀 구성원 | 다이제스트 회차(팀은 `?team_id=`)와 내용이 담긴 보고서 |
| `GET` `POST` | `/teams` | 이메일 확인 완료 | 내 팀 목록, 팀 생성(Team AI) |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | 구성원(조회), 소유자(변경, 삭제) | 구성원을 포함한 팀 |
| `PATCH` | `/teams/{id}/membership` | 구성원 | 팀 다이제스트 이메일 수신 여부 |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | 소유자 | 가입한 사용자를 이메일로 초대, 초대 철회 |
| `DELETE` | `/teams/{id}/members/{user}` | 소유자 또는 탈퇴하려는 구성원 본인 | 구성원 제거 |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | 초대받은 사용자 | 내 이메일로 온 초대, 수락 또는 거절 |
| `GET` | `/billing` | 이메일 확인 완료 | 적용 중인 요금제와 구독 |
| `POST` | `/billing/plan` | 이메일 확인 완료 | 요금제 선택. 결제가 필요하면 `checkout_url` 반환 |
| `POST` | `/billing/cancel`, `/billing/resume` | 이메일 확인 완료 | 기간 종료 시 취소 또는 구독 유지 |
| `GET` | `/billing/portal` | 이메일 확인 완료 | Stripe 고객 포털 URL |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | 사용자 목록, 역할과 요금제 변경 |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | 레거시를 포함한 모든 요금제, 요금제 관리 |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | 역할 권한 관리 |
| `GET` `POST` | `/admin/imports` | `manage_imports` | 가져오기 실행 내역, 가져오기를 대기열에 추가(202) |
| `GET` | `/admin/digests` | `manage_imports` | 상태, 생성 방식, 대체 사유, 지표가 포함된 다이제스트 회차(내용 제외) |
| `GET` | `/admin/registries` | `manage_imports` | 레지스트리별로 데이터를 쓰는 데 부족한 것(`status`: `active`, `confirmation_advised`, `paid_use_unconfirmed`, `needs_key`, `needs_permission`), 연락처, 변경할 설정과 각 설정의 지정 여부(값은 절대 표시하지 않음), 라이선스와 마지막 가져오기, 그리고 아직 가져올 수 없는 레지스트리. 웹 앱의 관리 > 레지스트리 접근에 표시 |
| `GET` | `/admin/trial-links` | `manage_imports` | 레지스트리 식별자를 공유하는 레코드 간 연결. `status` = `conflict`(기본값), `auto`, `confirmed`, `rejected` |
| `PATCH` | `/admin/trial-links/{id}` | `manage_imports` | 연결 확정 또는 거부(`status`: `confirmed` 또는 `rejected`). 레코드가 다시 그룹화됨 |

검색 필터: `q`, `condition`, `keyword`, `mesh_term`, `sponsor`, `country`, `city`, `status[]`, `phase[]`, `study_type[]`, `has_results`, `start_date_from`/`_to`, `first_posted_from`/`_to`, `last_update_from`/`_to`, 그리고 `latitude` + `longitude` + `radius_km`. `sort`는 `-last_update_post_date`(기본값), `last_update_post_date`, `±start_date`, `±study_first_post_date`, `±brief_title`, `relevance`를 받습니다.

토큰이 없거나 유효하지 않으면 401, 권한이 없거나 다른 사용자 또는 팀의 기록에 접근하면 403, 요금제 없이 유료 기능(AI 다이제스트, 팀 작업 공간)을 사용하면 402, 저장된 검색 한도에 도달하면 403, 입력이 유효하지 않으면 필드 오류와 함께 422를 반환합니다.

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 동작 방식

```text
Import:  scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
           → ClinicalTrialsGovClient (pages, retries) → StudyMapper → ClinicalTrialSynchronizer (one transaction per study)
           → ClinicalTrialsImported event → MatchAlertsJob → AlertMatcher → AlertNotifier (email)
Digests: scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identity, lock, entitlement)
           → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider or factual text) → DigestDeliverer (email)
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/`에 가져오기가 있습니다. 각 레지스트리는 `StudyRegistryClient`와 `StudyPayloadMapper`를 갖춘 `RegistrySource`(`app/Services/Registries/`)이며, `clinicaltrials:fetch --source=<key>`로 하나를 선택하고(기본값 `clinicaltrials_gov`), 레지스트리마다 자체 증분 커서를 유지합니다. 매퍼는 페이로드만으로 결과가 정해지는 순수 함수이며, 동기화기(synchronizer)가 영속화를 담당합니다.
- `app/Services/Registries/`에는 레지스트리 목록(`SourceRegistry`), 식별자 정규화(`IdentifierParser`), 중복 제거(`DuplicateLinker`, 각 연구의 트랜잭션 안에서 실행)가 있습니다. 가져오기 도구가 레지스트리에 접근하는 유일한 경로는 `Fetching/RegistryFetcher`입니다. `Parsing/`에는 HTML, CSV, XLSX, PDF 텍스트, 고정 폭 표 리더가 있고, `Sources/<Registry>/`에는 각 가져오기 도구(법률 검토가 담긴 소스, 클라이언트, 매퍼)가 있습니다.
- `app/Services/ClinicalTrials/Search/`는 `AppServiceProvider`에 등록된 작은 `TrialFilter` 클래스들로 검색을 구성합니다. 알림도 같은 조건과 필터를 재사용합니다.
- `app/Services/ClinicalTrials/Changes/`에는 연구의 추적 대상 사실과 이를 비교하는 순수 클래스 `ChangeDetector`가 있습니다.
- `app/Services/Alerts/`는 저장된 검색 한도를 적용하고, 가져온 연구와 일치 여부를 판단하며, 이메일을 보냅니다. `app/Services/Billing/`에는 요금제 규칙과 `Entitlements`가 있으며, Stripe는 `SubscriptionGateway` 인터페이스를 통해서만 호출합니다. `app/Services/Teams/`는 팀과 초대를 관리합니다.
- `app/Services/Digests/`는 다이제스트를 만들며, 모델은 `AiProvider` 인터페이스(`OpenAiCompatibleProvider`)를 통해 호출합니다.
- `app/Http/`는 컨트롤러를 얇게 유지합니다. Form Request가 검증하고, 서비스가 판단하며, API Resource가 JSON 형태를 만듭니다.
- Docker 스택은 일곱 개의 서비스로 구성됩니다: `app`(PHP 8.6 FPM), `queue`, `scheduler`, `mysql`(MySQL 8.4), `redis`(Redis 8.10, 캐시 저장소이며 요청 한도 제한과 JWT 블랙리스트에도 사용), `nginx`, `node`(Node.js 26 및 Vite). phpredis 확장이 아직 PHP 8.6에서 빌드되지 않으므로 Laravel은 Predis(`REDIS_CLIENT=predis`)를 통해 Redis와 통신합니다.

## 개발

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- PHP 테스트는 전용 MySQL 데이터베이스 `clinical_saas_test`에서 실행됩니다. 이 데이터베이스는 MySQL 볼륨을 처음 만들 때 `docker/mysql/init/`이 생성합니다. 기존 볼륨이라면 한 번만 직접 만드세요: `docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`.
- 가져오기 테스트는 `tests/Fixtures/`에 기록된 API 응답과 함께 `Http::fake()`를 사용합니다. 결제 테스트는 `Tests\Fakes\FakeSubscriptionGateway`를, 다이제스트 테스트는 `Tests\Fakes\FakeAiProvider`를 사용하며, 메일은 가짜로 대체됩니다. 테스트는 ClinicalTrials.gov, Stripe, AI 제공자를 절대 호출하지 않습니다.
- GitHub Actions: `.github/workflows/packages.yml`은 Composer와 npm 잠금 파일을 감사하고(*packages* 배지), `.github/workflows/ci.yml`은 `main`으로의 푸시와 풀 리퀘스트, 태그, 그리고 Actions 탭에서의 수동 실행 시 Pint, PHP 테스트, 타입 검사, Vue 테스트, 빌드를 실행합니다. 브랜치는 `dev`에서 `hom`을 거쳐 `main`으로 흐릅니다.

스키마는 `database/migrations/`의 마이그레이션으로만 정의됩니다. 스키마를 변경할 때마다 새 마이그레이션을 추가하고, 이미 실행된 마이그레이션은 수정하지 마세요.

## 프로젝트 구조

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

## 데이터 출처

연구 데이터는 미국 국립의학도서관(U.S. National Library of Medicine)이 운영하는 [ClinicalTrials.gov](https://clinicaltrials.gov/)에서 가져옵니다. 기록은 등록된 연구를 설명할 뿐 의학적 조언이 아닙니다. 연구의 세부 정보를 근거로 삼기 전에 공식 기록을 확인하세요. 다른 레지스트리는 [기타 레지스트리](#기타-레지스트리)에 나열되어 있으며, 각 레지스트리의 데이터는 출처와 함께 표시됩니다. [docs/registries-evaluation.md](docs/registries-evaluation.md)에는 조사한 모든 레지스트리의 법률 검토가 기록되어 있습니다.

## 라이선스

[MIT](LICENSE-MIT).
