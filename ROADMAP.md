# Roadmap

Clinical Trial Watcher imports clinical studies from ClinicalTrials.gov into a MySQL database and serves them through a Laravel API with JWT authentication. The goal is a service where users search studies and get alerts when new or updated trials match what they follow, with plan limits on the number of alerts. The foundations and the first five stages below are built; the next steps at the end are open.

Last updated: 2026-11-23. This roadmap tracks the `main` branch. Items are checked only when they are implemented and verified.

## Foundations

### 1. Document the project

Objective: make the purpose, setup and structure clear to users and contributors.

- [X] Add English and Brazilian Portuguese READMEs, a roadmap, a changelog and an `AGENTS.md` guide.
- [X] Choose a license and add the license file and badge.
- [X] Rename the Composer package and `APP_NAME` from the Laravel skeleton defaults.

Acceptance: a contributor can start the stack, import studies and log in to the API using the README.

### 2. Data model

Objective: store each study with the fields needed for search and alerts.

- [X] Model clinical trials with conditions, keywords, locations, interventions, arm groups and outcomes.
- [X] Add secondary identifiers, collaborators, publications and MeSH terms (primary and ancestor).
- [X] Store eligibility, enrollment, sponsor, FDA oversight, expanded access and IPD sharing fields.
- [X] Keep the full API response in `raw_json`.
- [X] Store `primary_completion_date` (column, `$fillable` and import mapping).
- [X] Fill `has_results` from the API's `hasResults` flag.
- [X] Add indexes for the fields used by search (`overall_status`, `phase`, `study_type`, `last_update_post_date`, `condition`, `country`).
- [X] Add model factories for the clinical trial tables.

Acceptance: an imported study can be read back with all its related rows, and no field read by the import is silently discarded.

### 3. Reliable import

Objective: import the full registry and keep it current without manual runs.

- [X] Import studies with `php artisan clinicaltrials:fetch`, matched by NCT number so re-runs update records.
- [X] Normalize partial dates (`YYYY`, `YYYY-MM`) to full dates.
- [X] Use the production endpoint `https://clinicaltrials.gov/api/v2/studies` instead of `beta-ut.clinicaltrials.gov`.
- [X] Remove `verify => false` so TLS certificates are checked.
- [X] Follow `nextPageToken` to read every page, not only the first.
- [X] Incremental sync: request only studies updated since the last run.
- [X] Wrap each study in a database transaction so a failure does not leave partial relations.
- [X] Retry with backoff on rate limits and network errors, and log a summary of each run.
- [X] Move the work into a queued job and schedule it in `routes/console.php`.
- [X] Always replace related lists, including when the API returns an empty list.

Acceptance: a scheduled run imports new and changed studies without duplicates, and a failed run can be repeated safely.

### 4. Authentication and authorization

Objective: secure the API and enforce roles and plans.

- [X] JWT login, logout and current user endpoints.
- [X] Roles (`Admin`, `User`) and permissions, seeded.
- [X] Plans (Basic, Pro, Enterprise) and a subscriptions table.
- [X] Return real HTTP statuses from the API (a failed login is HTTP 401).
- [X] Add `JWT_SECRET` and the Docker database values to `.env.example`.
- [X] Registration, token refresh, password reset and email verification.
- [X] Middleware or policies that check permissions (`view_clinical_trials`, `create_alerts`, `manage_*`).
- [X] Rate limiting on the login route.
- [X] Choose one token system: JWT. Sanctum was removed.

Acceptance: protected routes reject missing tokens and missing permissions with the right HTTP status.

### 5. Automated verification

Objective: catch regressions in the import and the API.

- [X] Feature tests for login, logout, `me` and invalid credentials.
- [X] Import tests with `Http::fake()` and recorded API responses, covering partial dates, missing modules and re-runs.
- [X] Run tests on a dedicated database (SQLite in memory or a test MySQL schema).
- [X] Run Pint in check mode.
- [ ] GitHub Actions running tests and Pint on pushes and pull requests. The workflow is in `.github/workflows/ci.yml`; check it off after its first green run on GitHub.

Acceptance: `php artisan test` passes locally and in CI, with tests for every API route and the import command.

## Feature stages

### Stage 1. Search API

Objective: let users find studies.

- [X] List studies with pagination and API resources.
- [X] Filter by condition, keyword, MeSH term, status, phase, study type, sponsor, country, city and dates.
- [X] Full-text search on titles and summaries.
- [X] Study detail endpoint with all related data.
- [X] Search by distance using location coordinates.

### Stage 2. Alerts

Objective: notify users when studies match what they follow.

- [X] Saved searches as alerts, limited by the plan's `alerts_limit`.
- [X] Match new and updated studies after each import.
- [X] Email notifications and a daily or weekly digest.
- [X] Track status changes, such as a study starting to recruit.

### Stage 3. Plans and billing

Objective: charge for paid plans.

- [X] Integrate a payment provider for subscriptions.
- [X] Trial period handling with `trial_ends_at`.
- [X] Upgrade, downgrade and cancellation.
- [X] Payment webhooks that update the `subscriptions` table.

### Stage 4. Web interface

Objective: give users a front end for search and alerts.

- [X] Search page and study page.
- [X] Account, alerts and plan management.
- [X] Administration of users, plans, roles and imports.

### Stage 5. More sources

Objective: cover studies outside ClinicalTrials.gov.

- [X] Evaluate other registries such as WHO ICTRP, EU CTR and ReBEC (Brazil).
- [X] Link publications to PubMed records by PMID.

## Next steps

Found while building the stages above.

- [ ] Test the billing flows against Stripe test mode (checkout, swap, cancellation, webhooks). Automated tests use a fake gateway and Cashier's webhook handler without Stripe.
- [ ] Browser tests for the web app (for example with Playwright). The Vue code is type-checked, unit-tested and built, but no end-to-end browser test exists.
- [ ] Prepare the schema for more than one registry (`source`, `source_id` and a table of linked identifiers such as EudraCT, EU CT and RBR numbers), as recommended in [docs/registries-evaluation.md](docs/registries-evaluation.md).
- [ ] Import from CTIS (EU) and then ReBEC, following the evaluation's priority order.
- [ ] Speed up the test database setup (for example `php artisan schema:dump`); running every migration takes about three minutes on Docker Desktop.
