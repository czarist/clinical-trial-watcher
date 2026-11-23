<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Import clinical studies from ClinicalTrials.gov, search them, and get an email when studies you follow open, change or start recruiting.</h3>

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
  <a href="#features">Features</a> ·
  <a href="#stack">Stack</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#api">API</a> ·
  <a href="#how-it-works">How it works</a> ·
  <a href="#development">Development</a> ·
  <a href="ROADMAP.md">Roadmap</a> ·
  <a href="CHANGELOG.md">Changelog</a>
</p>

## Features

### Import from ClinicalTrials.gov

- **The whole registry, then only what changed.** `php artisan clinicaltrials:fetch` reads the production API v2 (`https://clinicaltrials.gov/api/v2/studies`, TLS verified), follows `nextPageToken` through every page, and records each run. Later runs ask only for studies updated since the last successful run.
- **Runs every day.** The scheduler queues the import at 03:00 (`CLINICAL_TRIALS_SCHEDULE_TIME`); a queue worker runs it, one import at a time.
- **Safe to repeat.** Studies are matched by NCT number. Each study and its related lists are saved in one transaction, and every list is replaced with the current one, including empty lists. A study that fails is rolled back and counted; the run continues.
- **Failures are explicit.** Rate limits (HTTP 429), server errors and connection failures are retried with exponential backoff, a bounded number of times. A run that cannot read the registry is recorded as failed and the command exits with an error.
- **Changes are tracked with before-and-after values.** A new study is recorded as new and becomes the baseline. On later imports the overall recruitment status, the sites (added, removed, or with a changed site status), the start and completion dates (with their original precision: a month-only date stays a month) and posted results are compared and stored as structured details. Site lists are compared by facility and place, so a change in the order the registry lists them is not a change. Each change has a stable fingerprint, so a retried or repeated import cannot record it, or notify it, twice.
- **Partial dates are normalized** (`2024` → `2024-01-01`, `2024-06` → `2024-06-01`), and the full study payload is kept in `raw_json`.

### Search

- Paginated search with filters for words in the title and summary (MySQL full-text), condition, keyword, MeSH term, recruitment status, phase, study type, sponsor, country, city, start, first-posted and last-update dates, results, and distance from a point.
- A study page with every related list: sites, interventions, arms, outcomes, eligibility, identifiers, collaborators, MeSH terms, publications with links to PubMed, and the status changes recorded by the import.

### Alerts

- An alert is a saved search. After each import, new and updated studies that match it are recorded.
- Emails go out as soon as a study matches, or in a daily or weekly summary. Status changes, such as a study starting to recruit, can be included or left out.
- Basic alerts are free. Every plan has the same limit on saved searches (`SAVED_SEARCH_LIMIT`, 100 by default); saved searches created above a lower limit are kept.

### AI digests (Pro AI and Team AI)

- A saved search can have an AI digest, daily or weekly, in any of the seven languages below. Turning it off leaves the basic alert running.
- The backend picks the changes that matched the saved search in the reporting window, counts them, and ranks them from the saved-search criteria (for example, a newly recruiting site in a country the search monitors ranks above a date change). The model only words a short factual summary of each change from the recorded facts.
- Each digest shows the saved search, the period, the number of matching updates, the most relevant changes with why they match, and for every study its NCT number, registry update date and ClinicalTrials.gov link (built by the server from the validated NCT number). Missing facts are stated as missing, and a digest says when it lists only the most relevant updates.
- Only public registry facts are sent to the model: no account data, email addresses or user notes. Its answer must be JSON tied to the requested changes; summaries with links, other NCT numbers or wording about eligibility, treatment or efficacy are rejected.
- When AI is off, fails after bounded retries or returns invalid output, the digest uses a factual summary built from the recorded changes, and the edition records why (`ai_disabled`, `provider_error`, `invalid_output`).
- A summary is cached per change version, language and prompt version and reused by every subscriber; relevance is computed per saved search.
- Each edition has a stable identity (recipient or team, saved search and window) and one delivery row per recipient, so scheduler reruns and job retries do not send twice. Paid access and team membership are checked again before sending.
- Two sample editions with synthetic data are at `/digests/samples`, open without an account.

### Teams (Team AI)

- A Team AI subscriber owns one team, under one subscription. The owner invites registered users by email; they join only by accepting.
- Members see the team's shared saved searches and digest reports, and choose whether to receive the team's digests by email. Only the owner changes the team, its members and its shared searches. Every team route checks membership.
- When the owner's Team AI subscription ends, the shared searches and reports are paused; a member's own AI digests depend on their own plan.

### Accounts, plans and billing

- Registration with email confirmation, login with JWT, token refresh, logout, password reset, and profile and password changes.
- Roles and permissions enforced on every protected route: `view_clinical_trials`, `create_alerts`, `manage_users`, `manage_plans`, `manage_roles` and `manage_imports`.
- Plans, in US dollars:

  | Plan | Price | Includes |
  |---|---|---|
  | Free | $0 | Search, filters, study details, change history, saved searches and basic email alerts |
  | Pro AI | $19 per month | AI digests for your own saved searches |
  | Team AI | $99 per month per team | Shared saved searches, shared AI digests and a report page for each team digest |

- New accounts start on Free, without a trial.
- Paid plans through Stripe with Laravel Cashier: Stripe Checkout for the first subscription, switching between Pro AI and Team AI with proration, cancellation at the end of the period, resuming, and the Stripe customer portal. Stripe webhooks keep the account's plan in sync. Paid features last until the end of the paid period after a cancellation.
- Basic, Pro and Enterprise are legacy plans. They are no longer sold and grant no AI features; existing subscriptions on them, their prices and Stripe customers are left as they are, and their subscribers can still cancel, resume, use the customer portal or move to a current plan.

### Web app

A Vue 3 single-page app served by Laravel: search and study pages, alerts with their matches and AI digest settings, AI digest reports, sample digests, teams (members, invitations and shared searches), account and plan, and administration of users, plans, roles and imports.

### Languages

The web app, API messages, emails and AI digests are available in English (the default), Brazilian Portuguese, Spanish, Simplified Chinese, Traditional Chinese, Japanese and Korean.

- The web app starts in the browser's language when it is offered (otherwise English) and has a language menu in the header, on the login pages and on the account page. Dates, numbers and prices follow the chosen language.
- Each account stores its language (`locale`): it is set at registration and changed with the menu or `PUT /api/account/locale`. Emails (confirmation, password reset, alerts, team invitations) use the recipient's language; an AI digest uses the language chosen for its saved search.
- The API answers in the language of the `Accept-Language` header, which the web app always sends, and falls back to English. The response carries `Content-Language`.
- Registry data (study titles, summaries, conditions, sites) stays in the language of ClinicalTrials.gov; only the interface around it is translated.

Translations live in `lang/<locale>/` and `lang/<locale>.json` for the backend and in `resources/js/i18n/locales/` for the web app. English is the source: tests fail when another language misses a key or a placeholder.

> Study data comes from a public registry. It is not medical advice, a treatment recommendation or a decision about who can take part in a study.

## Stack

Versions installed on 2026-11-23. Constraints are in `composer.json` and `package.json`.

| Layer | Technology |
|---|---|
| Language and framework | PHP 8.6, Laravel 13.43 |
| Database | MySQL 8.4 LTS (data, full-text search, queue and sessions) |
| Cache | Redis 8.10 through Predis 3.7 (cache, rate limiting, JWT blacklist) |
| Authentication | JWT with `tymon/jwt-auth` 2.3 |
| Billing | Stripe through Laravel Cashier 16.11 |
| HTTP client | Guzzle 8.6 (Laravel HTTP client) for the ClinicalTrials.gov API |
| Email | Laravel Mail and notifications (`MAIL_MAILER`; `log` in local development) |
| Web app | Vue 3.5, Vue Router 5.5, Pinia 4.1, TypeScript 7.0, Public Sans (Google Fonts) |
| Front-end build | Vite 8.3, `laravel-vite-plugin` 3.2, `@vitejs/plugin-vue` 6.0 |
| PHP tests and quality | PHPUnit 13.4, Mockery 1.6, Faker 1.24, Collision 8.9, Laravel Pint 1.32, Ignition 2.13 (error pages) |
| Front-end tests and quality | Vitest 5.1, Vue Test Utils 2.6, jsdom 31, `vue-tsc` 3.3 (on `@typescript/typescript6` 6.0) |
| Tooling | Composer 2.11, Node.js 26, npm, Laravel Tinker 3.2 |
| Containers | Docker Compose: PHP-FPM, queue worker, scheduler, MySQL, Redis, nginx 1.30, Node.js (Vite) |
| CI | GitHub Actions |
| AI | Any OpenAI-compatible chat completions API, through the Laravel HTTP client (optional) |
| External services | ClinicalTrials.gov API v2, Stripe, an OpenAI-compatible AI provider, PubMed (links by PMID) |

Laravel Breeze 2.4 and Laravel Sail 1.71 are installed as development dependencies from the Laravel skeleton but are not used: the app has its own Vue front end and runs with its own Docker Compose file. TypeScript 7 checks the `.ts` files. Its native compiler no longer has the JavaScript API that `vue-tsc` needs, so `npm run typecheck:vue` runs `vue-tsc` on the official TypeScript 6 package, `@typescript/typescript6` (`scripts/vue-tsc.cjs`), to check the `.vue` files; `npm run typecheck` runs both. Redis uses Predis because the phpredis extension does not build on PHP 8.6 yet. `package.json` overrides `glob` and `minimatch` with patched versions, because `@vue/test-utils` still depends on vulnerable ones.

## Quick start

### Requirements

- Docker and Docker Compose
- Ports `8000` (web), `3306` (MySQL) and `5173` (Vite) free on the host, or other ports set in `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `VITE_PORT`)

### Run the stack

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

`.env.example` already has the database values used by `docker-compose.yml`. On start, the `app` container waits for MySQL, runs `composer install` if `vendor/` is missing, generates `APP_KEY` and `JWT_SECRET` when they are empty, and runs the migrations. The `queue` and `scheduler` containers run the queued jobs and the daily schedule, `redis` holds the cache, and the `node` container runs the Vite dev server.

Open `http://localhost:8000` and log in with the seeded administrator, `admin@admin.com` / `password`. These credentials are for local development only.

`--max-pages=1` imports a first page of 100 studies. Without it, the first run reads the whole registry, which takes hours.

To build the web app instead of running the dev server: `docker-compose run --rm node npm run build`.

### Billing setup

Search and basic alerts work without Stripe. To sell Pro AI and Team AI:

1. Set `STRIPE_KEY`, `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` in `.env`.
2. In Stripe, create two recurring monthly prices in USD: $19.00 for Pro AI and $99.00 for Team AI. Put their IDs in `STRIPE_PRICE_PRO_AI` and `STRIPE_PRICE_TEAM_AI`.
3. Run `docker-compose exec app php artisan billing:check-prices`. It copies the IDs to the plans and checks that each price is active and charges the plan's amount in USD every month; it fails and names the problem otherwise. Checkout runs the same check and refuses a mismatched price.
4. Point a Stripe webhook to `https://<your-host>/stripe/webhook` with the `customer.subscription.*` and `invoice.*` events.

Existing subscriptions: the migration `2026_11_24_000001_add_free_and_ai_plans` marks Basic, Pro and Enterprise as legacy without changing their prices, Stripe price IDs, subscriptions or customers. A subscriber on a legacy plan keeps it, billed as before, with no AI features. Keep the legacy plans' `stripe_price_id` set (Plans page in Administration) so webhooks keep matching their subscriptions; nothing needs to be mapped to the new plans. Accounts still inside the old 14-day signup trial now use Free.

### AI setup

AI digests work without a provider: they then use the factual summary. To have summaries written by a model, set in `.env`:

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # any OpenAI-compatible API
AI_API_KEY=...                          # server-side only
AI_MODEL=...                            # a model name the provider accepts
```

Limits: `AI_TIMEOUT` (seconds, 30), `AI_RETRIES` (2) and `AI_RETRY_SLEEP_MS` (2000), `AI_MAX_INPUT_CHARS` (12000 characters per request), `AI_MAX_OUTPUT_TOKENS` (1200) and `AI_DIGEST_MAX_ITEMS` (10 studies per digest). The log records each digest's status, duration, cache hits and token counts, never the API key or the model's input and output. Operators see each edition's generation (`ai`, `partial` or `fallback`) and the fallback reason at `GET /api/admin/digests`.

The scheduler queues daily digests at 07:30 and weekly digests on Mondays at 07:30 (`php artisan digests:dispatch daily|weekly`); the `queue` container generates and sends them.

### Check it locally

1. Log in as the seeded administrator and import a page of studies (above).
2. Create an alert on the Alerts page: it works on Free. Its AI digest section shows the sample digests and the upgrade action.
3. Open `http://localhost:8000/digests/samples` to see the two sample editions.
4. Without Stripe, give an account a plan by hand in Tinker to try the paid screens, for example a local `subscriptions` row with `stripe_status` `active` and the Pro AI or Team AI `plan_id`. Then turn on an AI digest, run another import and `php artisan digests:dispatch daily --end=tomorrow` (the window then includes today); with `MAIL_MAILER=log` the digest email is written to `storage/logs/laravel.log` and the report appears on the AI digests page.

## API

All routes are under `/api` and return JSON. Protected routes need `Authorization: Bearer <access_token>`.

| Method | Route | Access | Description |
|---|---|---|---|
| `POST` | `/auth/register` | public | Create an account (optional `locale`, else the request language); returns a token (201) |
| `POST` | `/auth/login` | public, 5 attempts per minute | Token, or HTTP 401 for invalid credentials |
| `POST` | `/auth/refresh` | token | New token |
| `POST` | `/auth/logout` | token | Invalidate the token (204) |
| `GET` | `/auth/me` | token | Current user, roles, permissions and billing summary |
| `POST` | `/auth/forgot-password` | public | Email a reset link |
| `POST` | `/auth/reset-password` | public | Set a new password with the emailed token |
| `GET` | `/auth/verify-email/{user}/{hash}` | signed link | Confirm the email and redirect to the web app |
| `POST` | `/auth/email/verification-notification` | token | Send the confirmation link again |
| `PUT` | `/account/profile`, `/account/password` | token | Change name and email, or password |
| `PUT` | `/account/locale` | token | Change the account language (`locale`: `en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/trials` | `view_clinical_trials` | Search; filters below, `sort`, `page`, `per_page` (up to 100) |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | Study with every related list |
| `GET` `POST` | `/alerts` | `create_alerts`, confirmed email | List or create saved searches; `team_id` for a team's shared ones (owner creates, members list) |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | owner; team members can read shared ones | Read, change or delete a saved search, including `ai_digest_enabled`, `ai_digest_frequency` (`daily`, `weekly`) and `ai_digest_language` (`en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/alerts/{id}/matches` | owner, team members | Studies that matched |
| `GET` | `/plans` | public | Free, Pro AI and Team AI with USD prices and features |
| `GET` | `/digests/samples` | public | Two sample digest editions with synthetic data |
| `GET` | `/digests`, `/digests/{id}` | confirmed email; recipient or team member | Digest editions (`?team_id=` for a team) and a report with its content |
| `GET` `POST` | `/teams` | confirmed email | Your teams; create one (Team AI) |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | member (read), owner (change, delete) | A team with its members |
| `PATCH` | `/teams/{id}/membership` | member | Receive the team's digests by email or not |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | owner | Invite a registered user by email; withdraw an invitation |
| `DELETE` | `/teams/{id}/members/{user}` | owner, or the member to leave | Remove a member |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | the invited user | Invitations to your email; accept or decline |
| `GET` | `/billing` | confirmed email | Effective plan and subscription |
| `POST` | `/billing/plan` | confirmed email | Choose a plan; returns `checkout_url` when payment is needed |
| `POST` | `/billing/cancel`, `/billing/resume` | confirmed email | Cancel at period end, or keep the subscription |
| `GET` | `/billing/portal` | confirmed email | Stripe customer portal URL |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | List users; change roles and plan |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | All plans, legacy included; manage plans |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | Manage role permissions |
| `GET` `POST` | `/admin/imports` | `manage_imports` | Import runs; queue an import (202) |
| `GET` | `/admin/digests` | `manage_imports` | Digest editions with status, generation, fallback reason and metrics (no content) |

Search filters: `q`, `condition`, `keyword`, `mesh_term`, `sponsor`, `country`, `city`, `status[]`, `phase[]`, `study_type[]`, `has_results`, `start_date_from`/`_to`, `first_posted_from`/`_to`, `last_update_from`/`_to`, and `latitude` + `longitude` + `radius_km`. `sort` accepts `-last_update_post_date` (default), `last_update_post_date`, `±start_date`, `±study_first_post_date`, `±brief_title` and `relevance`.

Missing or invalid tokens return 401, missing permissions or another user's or team's records 403, a paid feature without the plan (AI digests, team workspace) 402, the saved-search limit 403, and invalid input 422 with field errors.

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## How it works

```text
Import:  scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
           → ClinicalTrialsGovClient (pages, retries) → StudyMapper → ClinicalTrialSynchronizer (one transaction per study)
           → ClinicalTrialsImported event → MatchAlertsJob → AlertMatcher → AlertNotifier (email)
Digests: scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identity, lock, entitlement)
           → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider or factual text) → DigestDeliverer (email)
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` holds the import. The registry client is behind the `StudyRegistryClient` interface, the mapper is a pure function of the payload, and the synchronizer owns persistence.
- `app/Services/ClinicalTrials/Search/` builds the search from small `TrialFilter` classes registered in `AppServiceProvider`. Alerts reuse the same criteria and filters.
- `app/Services/ClinicalTrials/Changes/` holds the tracked facts of a study and the pure `ChangeDetector` that compares them.
- `app/Services/Alerts/` enforces the saved-search limit, matches imports and sends emails. `app/Services/Billing/` holds the plan rules and `Entitlements`; Stripe is reached through the `SubscriptionGateway` interface. `app/Services/Teams/` manages teams and invitations.
- `app/Services/Digests/` builds digests; the model is reached through the `AiProvider` interface (`OpenAiCompatibleProvider`).
- `app/Http/` keeps controllers thin: Form Requests validate, services decide, API Resources shape the JSON.
- The Docker stack has seven services: `app` (PHP 8.6 FPM), `queue`, `scheduler`, `mysql` (MySQL 8.4), `redis` (Redis 8.10, the cache store, also used by the rate limiter and the JWT blacklist), `nginx` and `node` (Node.js 26 and Vite). Laravel talks to Redis through Predis (`REDIS_CLIENT=predis`), because the phpredis extension does not build on PHP 8.6 yet.

## Development

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- The PHP tests run on a dedicated MySQL database, `clinical_saas_test`, created by `docker/mysql/init/` when the MySQL volume is first created. For an existing volume, create it once: `docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`.
- Import tests use `Http::fake()` with a recorded API response in `tests/Fixtures/`; billing tests use `Tests\Fakes\FakeSubscriptionGateway` and digest tests `Tests\Fakes\FakeAiProvider`, with mail faked. Tests never call ClinicalTrials.gov, Stripe or an AI provider.
- GitHub Actions: `.github/workflows/packages.yml` audits the Composer and npm lock files (the *packages* badge), and `.github/workflows/ci.yml` runs Pint, the PHP tests, the type check, the Vue tests and the build on pushes and pull requests to `main`, on tags, and by hand from the Actions tab. Branches flow from `dev` to `hom` to `main`.

The schema is defined only by the migrations in `database/migrations/`. Add a new migration for every schema change; do not edit migrations that have already run.

## Project layout

```text
app/
  Console/Commands/      clinicaltrials:fetch, alerts:send-digests, digests:dispatch, billing:check-prices
  Enums/                 statuses, phases, permissions, alert and digest frequency, team role
  Http/                  Controllers/Api (and Admin/), Requests/, Resources/, Middleware/
  Jobs/, Events/, Listeners/, Notifications/, Policies/
  Models/                User, Role, Permission, Plan, Subscription, Alert, AlertMatch, ImportRun,
                         Team, TeamMember, TeamInvitation, Digest, DigestItem, DigestDelivery, ChangeSummary
  Models/ClinicalTrials/ ClinicalTrial, its related lists and ClinicalTrialChange
  Services/              Auth/, ClinicalTrials/{Import,Search,Changes}, Alerts/, Billing/, Teams/, Digests/, Ai/, Admin/
config/                  clinicaltrials.php, billing.php, ai.php, auth.php, jwt.php
lang/                    API, email and digest text per language (en, pt_BR, es, zh_CN, zh_TW, ja, ko)
database/                migrations/, factories/, seeders/
docs/                    logo, registries evaluation
resources/js/            Vue app: pages/, components/, stores/, api/, i18n/ (catalogs per language)
routes/                  api.php, web.php (web app), console.php (schedule)
tests/                   Feature/, Unit/, Fixtures/
```

## Data source

Study data comes from [ClinicalTrials.gov](https://clinicaltrials.gov/), operated by the U.S. National Library of Medicine. Records describe registered studies and are not medical advice. Check the official record before relying on any study detail. [docs/registries-evaluation.md](docs/registries-evaluation.md) evaluates other registries (WHO ICTRP, EU CTR/CTIS and ReBEC).

## License

[MIT](LICENSE-MIT).
