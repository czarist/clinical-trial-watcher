<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Import clinical studies from ClinicalTrials.gov, search them, and get an email when studies you follow open, change or start recruiting.</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a>
</p>

<p align="center">
  <img alt="Status: early development" src="https://img.shields.io/badge/status-early_development-orange?style=flat-square">
  <img alt="PHP 8.6" src="https://img.shields.io/badge/PHP-8.6-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white">
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/runs%20with-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square">
</p>

<p align="center">
  <a href="#features">Features</a> ·
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
- **Changes are tracked.** New studies, updates and changes of recruitment status are recorded for alerts and shown in each study's history.
- **Partial dates are normalized** (`2024` → `2024-01-01`, `2024-06` → `2024-06-01`), and the full study payload is kept in `raw_json`.

### Search

- Paginated search with filters for words in the title and summary (MySQL full-text), condition, keyword, MeSH term, recruitment status, phase, study type, sponsor, country, city, start, first-posted and last-update dates, results, and distance from a point.
- A study page with every related list: sites, interventions, arms, outcomes, eligibility, identifiers, collaborators, MeSH terms, publications with links to PubMed, and the status changes recorded by the import.

### Alerts

- An alert is a saved search. After each import, new and updated studies that match it are recorded.
- Emails go out as soon as a study matches, or in a daily or weekly summary. Status changes, such as a study starting to recruit, can be included or left out.
- The number of alerts is limited by the plan (`alerts_limit`).

### Accounts, plans and billing

- Registration with email confirmation, login with JWT, token refresh, logout, password reset, and profile and password changes.
- Roles and permissions enforced on every protected route: `view_clinical_trials`, `create_alerts`, `manage_users`, `manage_plans`, `manage_roles` and `manage_imports`.
- New accounts get a 14-day trial with the Pro plan's limits (`TRIAL_DAYS`).
- Paid plans through Stripe with Laravel Cashier: Stripe Checkout for the first subscription, upgrades and downgrades with proration, cancellation at the end of the period, resuming, and the Stripe customer portal. Stripe webhooks keep the account's plan in sync.

### Web app

A Vue 3 single-page app served by Laravel: search and study pages, alerts with their matches, account and plan, and administration of users, plans, roles and imports.

> Study data comes from a public registry. It is not medical advice, a treatment recommendation or a decision about who can take part in a study.

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

`.env.example` already has the database values used by `docker-compose.yml`. On start, the `app` container waits for MySQL, runs `composer install` if `vendor/` is missing, generates `APP_KEY` and `JWT_SECRET` when they are empty, and runs the migrations. The `queue` and `scheduler` containers run the queued jobs and the daily schedule; the `node` container runs the Vite dev server.

Open `http://localhost:8000` and log in with the seeded administrator, `admin@admin.com` / `password`. These credentials are for local development only.

`--max-pages=1` imports a first page of 100 studies. Without it, the first run reads the whole registry, which takes hours.

To build the web app instead of running the dev server: `docker-compose run --rm node npm run build`.

### Billing setup

Set `STRIPE_KEY`, `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` in `.env`, create a recurring price in Stripe for each paid plan, and save its ID on the plan (`PATCH /api/admin/plans/{plan}` with `stripe_price_id`, or the Plans page in Administration). Point a Stripe webhook to `https://<your-host>/stripe/webhook` with the `customer.subscription.*` and `invoice.*` events.

## API

All routes are under `/api` and return JSON. Protected routes need `Authorization: Bearer <access_token>`.

| Method | Route | Access | Description |
|---|---|---|---|
| `POST` | `/auth/register` | public | Create an account; returns a token (201) |
| `POST` | `/auth/login` | public, 5 attempts per minute | Token, or HTTP 401 for invalid credentials |
| `POST` | `/auth/refresh` | token | New token |
| `POST` | `/auth/logout` | token | Invalidate the token (204) |
| `GET` | `/auth/me` | token | Current user, roles, permissions and billing summary |
| `POST` | `/auth/forgot-password` | public | Email a reset link |
| `POST` | `/auth/reset-password` | public | Set a new password with the emailed token |
| `GET` | `/auth/verify-email/{user}/{hash}` | signed link | Confirm the email and redirect to the web app |
| `POST` | `/auth/email/verification-notification` | token | Send the confirmation link again |
| `PUT` | `/account/profile`, `/account/password` | token | Change name and email, or password |
| `GET` | `/trials` | `view_clinical_trials` | Search; filters below, `sort`, `page`, `per_page` (up to 100) |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | Study with every related list |
| `GET` `POST` | `/alerts` | `create_alerts`, confirmed email | List or create alerts |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | owner | Read, change or delete an alert |
| `GET` | `/alerts/{id}/matches` | owner | Studies that matched |
| `GET` | `/plans` | public | Plans with prices and limits |
| `GET` | `/billing` | confirmed email | Effective plan and subscription |
| `POST` | `/billing/plan` | confirmed email | Choose a plan; returns `checkout_url` when payment is needed |
| `POST` | `/billing/cancel`, `/billing/resume` | confirmed email | Cancel at period end, or keep the subscription |
| `GET` | `/billing/portal` | confirmed email | Stripe customer portal URL |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | List users; change roles and plan |
| `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | Manage plans |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | Manage role permissions |
| `GET` `POST` | `/admin/imports` | `manage_imports` | Import runs; queue an import (202) |

Search filters: `q`, `condition`, `keyword`, `mesh_term`, `sponsor`, `country`, `city`, `status[]`, `phase[]`, `study_type[]`, `has_results`, `start_date_from`/`_to`, `first_posted_from`/`_to`, `last_update_from`/`_to`, and `latitude` + `longitude` + `radius_km`. `sort` accepts `-last_update_post_date` (default), `last_update_post_date`, `±start_date`, `±study_first_post_date`, `±brief_title` and `relevance`.

Missing or invalid tokens return 401, missing permissions 403, invalid input 422 with field errors, and the plan's alert limit 403.

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
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` holds the import. The registry client is behind the `StudyRegistryClient` interface, the mapper is a pure function of the payload, and the synchronizer owns persistence.
- `app/Services/ClinicalTrials/Search/` builds the search from small `TrialFilter` classes registered in `AppServiceProvider`. Alerts reuse the same criteria and filters.
- `app/Services/Alerts/` enforces the plan limit, matches imports and sends emails. `app/Services/Billing/` holds the plan rules; Stripe is reached through the `SubscriptionGateway` interface.
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
- Import tests use `Http::fake()` with a recorded API response in `tests/Fixtures/`; tests never call ClinicalTrials.gov or Stripe.
- GitHub Actions (`.github/workflows/ci.yml`) runs Pint, the PHP tests, the type check, the Vue tests and the build on pushes and pull requests to `main`, on tags, and by hand from the Actions tab. Branches flow from `dev` to `hom` to `main`.

The schema is defined only by the migrations in `database/migrations/`. Add a new migration for every schema change; do not edit migrations that have already run.

## Project layout

```text
app/
  Console/Commands/      clinicaltrials:fetch, alerts:send-digests
  Enums/                 statuses, phases, permissions, alert frequency
  Http/                  Controllers/Api (and Admin/), Requests/, Resources/, Middleware/
  Jobs/, Events/, Listeners/, Notifications/, Policies/
  Models/                User, Role, Permission, Plan, Subscription, Alert, AlertMatch, ImportRun
  Models/ClinicalTrials/ ClinicalTrial, its related lists and ClinicalTrialChange
  Services/              Auth/, ClinicalTrials/Import, ClinicalTrials/Search, Alerts/, Billing/, Admin/
config/                  clinicaltrials.php, billing.php, auth.php, jwt.php
database/                migrations/, factories/, seeders/
docs/                    logo, registries evaluation
resources/js/            Vue app: pages/, components/, stores/, api/
routes/                  api.php, web.php (web app), console.php (schedule)
tests/                   Feature/, Unit/, Fixtures/
```

## Data source

Study data comes from [ClinicalTrials.gov](https://clinicaltrials.gov/), operated by the U.S. National Library of Medicine. Records describe registered studies and are not medical advice. Check the official record before relying on any study detail. [docs/registries-evaluation.md](docs/registries-evaluation.md) evaluates other registries (WHO ICTRP, EU CTR/CTIS and ReBEC).

## License

[MIT](LICENSE-MIT).
