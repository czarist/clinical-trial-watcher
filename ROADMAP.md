# Roadmap

Clinical Trial Watcher imports clinical studies from ClinicalTrials.gov into a MySQL database and serves them through a Laravel API with JWT authentication. The goal is a service where users search studies and get alerts when new or updated trials match what they follow, with plan limits on the number of alerts. The foundations below are partly built; the feature stages are planned.

Last updated: 2026-11-05. This roadmap tracks the `main` branch. Items are checked only when they are implemented and verified.

## Foundations

### 1. Document the project

Objective: make the purpose, setup and structure clear to users and contributors.

- [x] Add English and Brazilian Portuguese READMEs, a roadmap, a changelog and an `AGENTS.md` guide.
- [ ] Choose a license and add the license file and badge.
- [ ] Rename the Composer package and `APP_NAME` from the Laravel skeleton defaults.

Acceptance: a contributor can start the stack, import studies and log in to the API using the README.

### 2. Data model

Objective: store each study with the fields needed for search and alerts.

- [x] Model clinical trials with conditions, keywords, locations, interventions, arm groups and outcomes.
- [x] Add secondary identifiers, collaborators, publications and MeSH terms (primary and ancestor).
- [x] Store eligibility, enrollment, sponsor, FDA oversight, expanded access and IPD sharing fields.
- [x] Keep the full API response in `raw_json`.
- [ ] Store `primary_completion_date`: the import reads it, but the column does not exist and the field is not fillable, so the value is dropped.
- [ ] Fill `has_results` from the API's `hasResults` flag.
- [ ] Add indexes for the fields used by search (`overall_status`, `phase`, `study_type`, `last_update_post_date`, `condition`, `country`).
- [ ] Add model factories for the clinical trial tables.

Acceptance: an imported study can be read back with all its related rows, and no field read by the import is silently discarded.

### 3. Reliable import

Objective: import the full registry and keep it current without manual runs.

- [x] Import studies with `php artisan clinicaltrials:fetch`, matched by NCT number so re-runs update records.
- [x] Normalize partial dates (`YYYY`, `YYYY-MM`) to full dates.
- [ ] Use the production endpoint `https://clinicaltrials.gov/api/v2/studies` instead of `beta-ut.clinicaltrials.gov`.
- [ ] Remove `verify => false` so TLS certificates are checked.
- [ ] Follow `nextPageToken` to read every page, not only the first.
- [ ] Incremental sync: request only studies updated since the last run.
- [ ] Wrap each study in a database transaction so a failure does not leave partial relations.
- [ ] Retry with backoff on rate limits and network errors, and log a summary of each run.
- [ ] Move the work into a queued job and schedule it in `routes/console.php`.
- [ ] Always replace related lists, including when the API returns an empty list (today an empty list keeps the old rows).

Acceptance: a scheduled run imports new and changed studies without duplicates, and a failed run can be repeated safely.

### 4. Authentication and authorization

Objective: secure the API and enforce roles and plans.

- [x] JWT login, logout and current user endpoints.
- [x] Roles (`Admin`, `User`) and permissions, seeded.
- [x] Plans (Basic, Pro, Enterprise) and a subscriptions table.
- [ ] Return the HTTP status from `AuthService` (today a failed login returns HTTP 200 with `status_code: 401` in the body).
- [ ] Add `JWT_SECRET` and the Docker database values to `.env.example`.
- [ ] Registration, token refresh, password reset and email verification.
- [ ] Middleware or policies that check permissions (`view_clinical_trials`, `create_alerts`, `manage_*`).
- [ ] Rate limiting on the login route.
- [ ] Choose one token system: JWT is used for the API, but Sanctum is still installed and `User` uses `HasApiTokens`.

Acceptance: protected routes reject missing tokens and missing permissions with the right HTTP status.

### 5. Automated verification

Objective: catch regressions in the import and the API.

- [ ] Feature tests for login, logout, `me` and invalid credentials.
- [ ] Import tests with `Http::fake()` and recorded API responses, covering partial dates, missing modules and re-runs.
- [ ] Run tests on a dedicated database (SQLite in memory or a test MySQL schema).
- [ ] Run Pint in check mode.
- [ ] GitHub Actions running tests and Pint on pushes and pull requests.

Acceptance: `php artisan test` passes locally and in CI, with tests for every API route and the import command.

## Feature stages

### Stage 1. Search API

Objective: let users find studies.

- [ ] List studies with pagination and API resources.
- [ ] Filter by condition, keyword, MeSH term, status, phase, study type, sponsor, country, city and dates.
- [ ] Full-text search on titles and summaries.
- [ ] Study detail endpoint with all related data.
- [ ] Search by distance using location coordinates.

### Stage 2. Alerts

Objective: notify users when studies match what they follow.

- [ ] Saved searches as alerts, limited by the plan's `alerts_limit`.
- [ ] Match new and updated studies after each import.
- [ ] Email notifications and a daily or weekly digest.
- [ ] Track status changes, such as a study starting to recruit.

### Stage 3. Plans and billing

Objective: charge for paid plans.

- [ ] Integrate a payment provider for subscriptions.
- [ ] Trial period handling with `trial_ends_at`.
- [ ] Upgrade, downgrade and cancellation.
- [ ] Payment webhooks that update the `subscriptions` table.

### Stage 4. Web interface

Objective: give users a front end for search and alerts.

- [ ] Search page and study page.
- [ ] Account, alerts and plan management.
- [ ] Administration of users, plans, roles and imports.

### Stage 5. More sources

Objective: cover studies outside ClinicalTrials.gov.

- [ ] Evaluate other registries such as WHO ICTRP, EU CTR and ReBEC (Brazil).
- [ ] Link publications to PubMed records by PMID.
