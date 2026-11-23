# Roadmap

Clinical Trial Watcher imports clinical studies from ClinicalTrials.gov into a MySQL database, caches with Redis, and serves them through a Laravel API with JWT authentication and a Vue web app. The goal is a free service where users search studies and get alerts when new or updated trials match what they follow, with paid AI digests (Pro AI and Team AI) that explain what changed. The foundations and the stages below are built; the next steps at the end are open.

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
- [X] GitHub Actions running Pint, the PHP tests and the web app checks (`.github/workflows/ci.yml`) on pushes and pull requests to `main`, on tags, and by hand.

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

- [X] Saved searches as alerts, limited by the plan's `alerts_limit` (replaced in stage 6 by one limit for every plan).
- [X] Match new and updated studies after each import.
- [X] Email notifications and a daily or weekly digest.
- [X] Track status changes, such as a study starting to recruit.

### Stage 3. Plans and billing

Objective: charge for paid plans.

- [X] Integrate a payment provider for subscriptions.
- [X] Trial period handling with `trial_ends_at` (new accounts no longer get a trial since stage 6).
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

### Stage 6. Free core and AI digests

Objective: make monitoring free and charge for AI-written digests that explain what changed in the studies a user follows, why each change matched, and where to verify it. Plans: Free ($0), Pro AI ($19 USD/month), Team AI ($99 USD/month per team).

Plans and entitlements

- [X] New plans Free, Pro AI and Team AI with prices stored in USD; Basic, Pro and Enterprise kept as legacy plans (existing subscriptions, customers and Stripe prices untouched, hidden from new checkout).
- [X] New accounts start on Free without the signup trial.
- [X] Search, study details, change history and basic alerts need no paid plan; one configurable saved-search limit for every plan, keeping existing alerts above it.
- [X] Entitlement service: personal AI digests (Pro AI, Team AI) and team workspace access (Team AI), following Cashier's grace period and cancellation dates.
- [X] Stripe Price IDs for Pro AI and Team AI read from configuration; price, currency and interval validated before checkout and by `php artisan billing:check-prices`.

Teams

- [X] Teams with one billable owner (the Team AI subscriber), invitations of registered users by email, explicit acceptance, member removal and leaving.
- [X] Shared saved searches and team digest reports, with membership enforced on every team route and isolation between teams.

Study change tracking

- [X] Store site-level recruitment status for locations.
- [X] Snapshot of tracked facts per study (overall status, sites, key dates with their original precision, results flag), with a baseline for studies imported before the snapshot existed.
- [X] Structured before-and-after details on change records for overall status, sites added/removed/updated, key dates and results posted; unordered site lists normalized so reordering is not a change.
- [X] Stable change fingerprints so retries and repeated imports do not duplicate changes or alert matches.

AI digests

- [X] Saved-search settings for AI digests: on/off, daily or weekly, English or Brazilian Portuguese; turning them off keeps basic alerts.
- [X] Backend ranking of matching changes from the saved-search criteria, with the reasons passed to the model.
- [X] Provider interface with an OpenAI-compatible client (base URL, key, model, timeout, feature toggle) and a validated JSON response.
- [X] Shared summary cache per change version, language and prompt version; input, output and item limits; logged status, duration, cache hits and token usage.
- [X] Deterministic factual digest as the fallback when AI is disabled, fails or returns invalid output, recorded on the digest.
- [X] Digests with a stable identity (alert and reporting window), queued generation, deduplicated delivery per recipient, and entitlement and membership rechecked before sending.
- [X] Scheduled daily and weekly digest runs.

Web app

- [X] Plans shown as Free, Pro AI $19/month and Team AI $99/month per team on the account and billing screens.
- [X] AI digest controls in the saved-search editor, with a preview and upgrade action for free users.
- [X] Two labeled sample digest editions viewable before subscribing.
- [X] Digest report pages and a team page for members, invitations and shared searches.

Verification and documentation

- [X] Tests for free access, Pro and Team entitlements, team isolation, legacy billing, change detection, import idempotency, digest scheduling and deduplication, invalid AI output and provider failure.
- [X] READMEs (plan model, Stripe and AI configuration, local verification) and changelog updated.

### Stage 7. Internationalization

Objective: offer the web app, API messages, emails and digests in English (the default and fallback), Brazilian Portuguese, Spanish, Simplified Chinese, Traditional Chinese, Japanese and Korean.

- [X] Supported locales defined once on each side (a backend enum and a web app list): `en`, `pt-BR`, `es`, `zh-CN`, `zh-TW`, `ja`, `ko`.
- [X] Account language stored on users (new migration), set at registration from the browser language and editable on the account page and through the API.
- [X] API messages (validation, authentication, business errors) answered in the request's language (`Accept-Language`), falling back to English.
- [X] Emails (alert matches, team invitations, email verification, password reset, AI digests) sent in the recipient's language.
- [X] AI digests in all seven languages: prompt, localized factual fallback, localized dates and advice/efficacy wording checks for each language.
- [X] Web app text, registry labels (status, phase, study type), dates, numbers and prices localized, with a language switcher; `<html lang>` follows the choice.
- [X] Tests: every locale has every key (backend and web app), language negotiation, and localized API, email and digest output.
- [X] READMEs, changelog and agent guide updated.
- [X] README translated into every offered language (`README.es.md`, `README.zh-CN.md`, `README.zh-TW.md`, `README.ja.md`, `README.ko.md`), with links between all of them.

## Next steps

Found while building the stages above.

- [ ] Test the billing flows against Stripe test mode (checkout, swap, cancellation, webhooks). Automated tests use a fake gateway and Cashier's webhook handler without Stripe.
- [ ] Browser tests for the web app (for example with Playwright). The Vue code is type-checked, unit-tested and built, but no end-to-end browser test exists.
- [ ] Prepare the schema for more than one registry (`source`, `source_id` and a table of linked identifiers such as EudraCT, EU CT and RBR numbers), as recommended in [docs/registries-evaluation.md](docs/registries-evaluation.md).
- [ ] Import from CTIS (EU) and then ReBEC, following the evaluation's priority order.
- [ ] Switch the Redis client from Predis to the phpredis extension once a phpredis release builds on PHP 8.6 (installed with PIE, since PHP 8.6 images no longer include PECL).
- [ ] Decide whether the queue and sessions should also move to Redis; today only the cache uses it.
- [ ] Drop `@typescript/typescript6`, `scripts/vue-tsc.cjs` and the `glob`/`minimatch` overrides once `vue-tsc` supports TypeScript 7 and `@vue/test-utils` ships patched dependencies.
- [ ] Operator actions for stage 6: create the Pro AI ($19) and Team AI ($99) monthly USD prices in Stripe, set `STRIPE_PRICE_PRO_AI` and `STRIPE_PRICE_TEAM_AI`, run `php artisan billing:check-prices`, and set the `AI_*` variables. Then test checkout and digests against Stripe test mode and the chosen AI provider; automated tests use fakes for both.
- [ ] Browser tests for the team, saved-search and digest screens, together with the end-to-end tests above.
- [ ] Speed up the test database setup (for example `php artisan schema:dump`); running every migration takes about three minutes on Docker Desktop.
