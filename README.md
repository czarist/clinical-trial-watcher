<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Collect clinical studies from ClinicalTrials.gov, store them in a searchable database, and serve them through an authenticated API.</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a>
</p>

<p align="center">
  <b>A Laravel back end for watching medical research: import studies, keep them up to date, and, in later stages, alert users when new trials match their interests.</b>
</p>

<p align="center">
  <img alt="Status: early development" src="https://img.shields.io/badge/status-early_development-orange?style=flat-square">
  <img alt="PHP 8.3" src="https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 11" src="https://img.shields.io/badge/Laravel-11-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="MySQL 8.0" src="https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/runs%20with-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="Auth: JWT" src="https://img.shields.io/badge/auth-JWT-000000?style=flat-square&logo=jsonwebtokens&logoColor=white">
</p>

<p align="center">
  <a href="#features">Features</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#how-it-works">How it works</a> ·
  <a href="#development">Development</a> ·
  <a href="ROADMAP.md">Roadmap</a> ·
  <a href="CHANGELOG.md">Changelog</a>
</p>

## Features

The project is in early development. What exists today is the data foundation: the import, the database model and authentication. Search, alerts and billing are planned in the [roadmap](ROADMAP.md).

### Import studies from ClinicalTrials.gov

- **One command imports studies.** `php artisan clinicaltrials:fetch` reads studies from the ClinicalTrials.gov API v2 and saves them. `--pageSize` sets how many studies are read (default 100).
- **Re-running updates instead of duplicating.** Each study is matched by its NCT number (`nct_id`), so running the import again refreshes existing records. Related lists (conditions, locations, outcomes and so on) are replaced with the current version from the API.
- **Dates are normalized.** The API returns partial dates such as `2024` or `2024-06`; they are stored as full dates (`2024-01-01`, `2024-06-01`).
- **The original record is kept.** The full API response for each study is stored in `raw_json`, so fields that are not mapped yet can be added later without fetching again.

### What is stored for each study

| Data | Table |
|---|---|
| Identification, titles, acronym, status, dates, phase, study type, enrollment, sponsor, eligibility (criteria, ages, sex), FDA oversight, expanded access, IPD sharing | `clinical_trials` |
| Conditions studied | `clinical_trial_conditions` |
| Keywords | `clinical_trial_keywords` |
| MeSH terms, primary and ancestor terms | `clinical_trial_mesh_terms` |
| Sites: facility, city, state, country, ZIP, coordinates | `clinical_trial_locations` |
| Interventions: type, name, description | `clinical_trial_interventions` |
| Arm groups | `clinical_trial_arm_groups` |
| Primary, secondary and other outcomes | `clinical_trial_outcomes` |
| Secondary identifiers | `clinical_trial_secondary_ids` |
| Collaborators | `clinical_trial_collaborators` |
| Publications (PMID, citation, link) | `clinical_trial_references` |

### Accounts and access

- **JWT authentication** for the API: log in with email and password, receive a bearer token, read the current user, and log out.
- **Roles and permissions.** `Admin` has every permission; `User` can view clinical trials and create alerts.
- **Plans and subscriptions (data model only).** Three seeded plans, Basic (free, 5 alerts), Pro (29.99/month, 50 alerts) and Enterprise (99.99/month, unlimited), plus a `subscriptions` table ready for a payment provider. No payment flow exists yet.

## Quick start

### Requirements

- Docker and Docker Compose
- Ports `8000` (web), `3306` (MySQL) and `5173` (Vite) free on the host

### Run the stack

```sh
cp .env.example .env
```

Set the database values in `.env` to match `docker-compose.yml`:

```dotenv
DB_DATABASE=clinical_saas
DB_USERNAME=clinical_user
DB_PASSWORD=clinical_pass
```

Then start the containers and finish the setup:

```sh
docker-compose up -d --build
docker-compose exec app php artisan jwt:secret
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch
```

On start, the `app` container waits for MySQL, runs `composer install` if `vendor/` is missing, generates `APP_KEY` if it is not set, and runs the migrations. The API is served at `http://localhost:8000`.

The seeder creates an administrator account: `admin@admin.com` with password `password`. Change it outside local development.

### Try the API

```sh
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}'

curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer <access_token>"
```

| Method | Route | Auth | Returns |
|---|---|---|---|
| `POST` | `/api/auth/login` | none | `access_token`, `token_type`, `expires_in` |
| `POST` | `/api/auth/logout` | bearer token | Confirmation message |
| `GET` | `/api/auth/me` | bearer token | The current user |
| `GET` | `/up` | none | Health check |

## How it works

```text
Import:  artisan clinicaltrials:fetch -> ClinicalTrials.gov API v2 -> FetchClinicalTrials -> clinical_trials + related tables
API:     client -> nginx -> PHP-FPM (Laravel) -> routes/api.php -> AuthController -> AuthService -> JWT guard
```

- `app/Console/Commands/FetchClinicalTrials.php` reads the API's `protocolSection` and `derivedSection` modules, maps them to columns, and saves the study and its related rows.
- `app/Models/ClinicalTrials/` holds `ClinicalTrial` and one model for each related list; `ClinicalTrial` defines every `hasMany` relation.
- `app/Models/` holds `User`, `Role`, `Permission`, `Plan` and `Subscription`. `User` implements `JWTSubject` for `tymon/jwt-auth`.
- `app/Http/Controllers/Api/AuthController.php` validates input with `LoginRequest` and delegates to `app/Services/AuthService.php`.
- `config/auth.php` defines the `api` guard with the `jwt` driver; `config/jwt.php` configures the tokens.

The Docker stack has four services: `app` (PHP 8.3 FPM with Composer and Node.js), `mysql` (MySQL 8.0), `nginx` (serves `public/` and forwards PHP to `app:9000`), and `node` (runs the Vite dev server).

## Development

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint
docker-compose exec app php artisan migrate:fresh --seed
```

- `php artisan test` runs PHPUnit. The suite currently contains only the framework's example tests.
- `./vendor/bin/pint` formats the code with Laravel Pint.
- `php artisan migrate:fresh --seed` rebuilds the database with plans, roles, permissions and the administrator account.

The schema is defined only by the migrations in `database/migrations/`. Add a new migration for every schema change; do not edit migrations that have already run.

## Project layout

```text
app/
  Console/Commands/     FetchClinicalTrials.php
  Http/Controllers/Api/ AuthController.php
  Http/Requests/        LoginRequest.php
  Models/               User, Role, Permission, Plan, Subscription
  Models/ClinicalTrials/ ClinicalTrial and its related models
  Services/             AuthService.php
config/                 auth.php (api guard), jwt.php
database/
  migrations/           users, SaaS tables, clinical trial tables
  seeders/              InitialSaasSeeder (plans, roles, permissions), UserSeeder (admin)
nginx/default.conf
routes/                 api.php, web.php, console.php
tests/                  Feature/, Unit/
docker-compose.yml
Dockerfile
entrypoint.sh
```

## Data source

Study data comes from [ClinicalTrials.gov](https://clinicaltrials.gov/), operated by the U.S. National Library of Medicine. Records describe registered studies and are not medical advice. Check the official record before relying on any study detail.

## License

No license has been chosen for this project yet.
