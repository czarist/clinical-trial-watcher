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

### Stage 8. More registries

Objective: import studies from registries beyond ClinicalTrials.gov, one importer per source, with the same idempotent sync, change tracking, alerts and digests. The access methods below come from a first survey and must be confirmed (terms, rate limits, fields) before each importer is built; record the findings in [docs/registries-evaluation.md](docs/registries-evaluation.md).

Foundations (needed before any second source)

- [X] Multi-registry schema through new migrations: `source` and `source_id` on studies, keeping `nct_id` working for ClinicalTrials.gov, and a table of linked identifiers (NCT, ISRCTN, EudraCT, EU CT, DRKS, REEC, CRIS, UMIN, jRCT, ACTRN, RBR, UTN and others).
- [X] Importer contract per source (fetch, map to the common study model, incremental cursor, `ImportRun` per source) and a `--source` option on the import command, keeping `clinicaltrials:fetch` compatible.
- [X] Deduplication through normalized secondary identifiers, never titles: records of the same study are linked as reviewable matches, keeping each source's conflicting values attributed to it.
- [X] Search, study page, alerts and digests aware of the source (filter by registry, link to the source record, source shown on every item).

Scraping (for sources without an API or export)

- [X] Scraping importer base: an identified user agent with a contact address, `robots.txt` honoured, per-source rate limit and concurrency of one, bounded retries with backoff, conditional requests (`ETag`/`Last-Modified`) and a raw-page cache so a parser fix does not refetch.
- [X] Legal gate per source before any scraper ships: terms of use and `robots.txt` checked and recorded in the evaluation document; never bypass blocks, logins, captchas or rate limits; when automated access is refused or blocked, ask the operator for permission or an export instead.
- [X] Parsers kept separate from fetching: HTML listings and detail pages (selectors per source, with recorded fixtures), and PDF tables (text extraction, then table parsing) for registries that publish only PDFs.
- [X] Change detection for scraped records (content hash of the normalized record), and layout-drift alerts: a run that parses far fewer records or fields than the last one is marked `partial` and logged instead of deleting data.
- [X] Keep the raw page or file of each scraped record (like `raw_json`) so fields can be remapped without fetching again.

Importers

- [X] ISRCTN (international): XML API, incremental by last-edit windows; CC BY / CC0, commercial use allowed. IRAS numbers are linked too.
- [X] Health Canada Clinical Trials Database: JSON API, full list compared by content hash; Open Government Licence. No registry identifiers, so no linking.
- [X] CTIS (EU/EEA): portal JSON API (search and retrieve), incremental by last update; EMA legal notice, credit EMA. Written confirmation from EMA about automated calls is still advisable.
- [X] EU Clinical Trials Register / EudraCT: plain-text export, one record per EudraCT number, NCT/ISRCTN/UTN linked; new trials only in incremental runs.
- [X] DRKS (Germany): JSON dump streamed from the ZIP; only CC BY 4.0 records (registered or updated from 2025). Older records need BfArM permission.
- [X] REEC (Spain): documented REST API, keyed by EU CT/EudraCT number so it links to CTIS; re-reads the last 90 days of registrations.
- [ ] CRIS (South Korea): blocked — KOGL Type 2 forbids commercial use and the website disallows all crawlers; needs written permission from KDCA. (API with a service key, no NCT field.)
- [ ] UMIN-CTR (Japan): blocked — the FAQ forbids commercial web services that display the data; needs written permission from UMIN Center.
- [X] TFDA Drug Clinical Trial Status (Taiwan): monthly ZIP of CSV (export 205), rows grouped per trial; no trial-level status and no registry identifiers.
- [ ] ANZCTR (Australia and New Zealand): blocked — no API, and automated collection needs ANZCTR's express approval (ideally an XML feed).
- [X] RENIS (Argentina): cumulative XLSX (newest file found on the dataset page, or `REGISTRY_RENIS_FILE_URL`), CC BY 4.0, NCT numbers linked. SISA (reCAPTCHA) is not read.
- [X] ReBEC (Brazil): HTML list and trial pages (robots.txt disallows the TRDS XML); commercial use not confirmed, so kept out of AI digests. Ask DECIT/Fiocruz to confirm reuse or allow the XML.
- [ ] WHO ICTRP (international): blocked — robots.txt disallows the whole site, bulk files need paid institutional access, and section 4(d) forbids commercial use. Needs WHO access and a written waiver; until then the registries it aggregates are imported from their own sources.
- [ ] MFDS approved trial plans (South Korea, data.go.kr `ClncExamPlanDtlService03`): REST API, "no restriction" on use; needs an operator key with production approval and a real response to build the mapper from.
- [ ] NCI Clinical Trials Search API v2 (US, cancer): needs an operator key and a reading of its terms; enrich existing NCT studies instead of duplicating them.
- [X] EDA clinical trials registry (Egypt): cumulative PDF parsed with `pdftotext -layout`; no registry id (study code used, never for linking); commercial use not confirmed.
- [ ] CDE/NMPA drug trial registration and disclosure platform (China): blocked — every page returns an anti-bot challenge; needs written permission or a data arrangement with CDE.
- [ ] HMA-EMA catalogue of real-world data studies (EU): blocked — robots.txt disallows `/search` (listing and CSV export); needs permission or an export from EMA.
- [ ] HRA Research Summaries (UK): blocked — reuse only "for research or private study"; needs written permission from HRA. Records carry IRAS ids (now linkable).
- [ ] RNEC (Portugal, INFARMED): blocked — reuse only "not for profit"; its trials are CTIS trials (same EU CT numbers), already covered.
- [ ] INCa cancer trials registry (France): site moved to cancer.fr and was in maintenance on 2026-11-23; re-check, and evaluate the API ECLAIRE (FHIR, data.gouv.fr) instead.
- [ ] Orphanet research and clinical trials (rare diseases): blocked — trials only in Orphadata dumps under an Orphanet-AISBL contract; the CC BY 4.0 ORPHAcode nomenclature can tag rare-disease conditions without it.
- [ ] Plataforma Brasil (CEP/Conep): blocked — public pages lack CAAE, phase and status; needs an official extract from Conep/CNS. ReBEC records already cite CAAE numbers (linkable).
- [ ] TİTCK Clinical Research Portal (Turkey): blocked — a firewall rejects even robots.txt; needs permission and an allow-listed route or export.
- [X] MOZ orders authorizing trials (Ukraine): orders listing and annex PDFs; amendment annexes skipped; commercial use not confirmed. The State Expert Center register is behind a firewall: ask for access.
- [X] Pharmacy and Poisons Board trials system (Kenya): approved, stopped and suspended lists and detail pages; no registry identifiers; commercial use not confirmed.
- [ ] SÚKL clinical trials database (Czechia): blocked — prior consent required; duplicates EU CTR and CTIS, not worth asking.
- Not planned: EMA Clinical Data Publication (terms allow only non-commercial use, login required; link to it only); Australian Clinical Trials and Australian Cancer Trials (copies of ANZCTR); KDCA registration statistics (from CRIS); AACT (a ClinicalTrials.gov mirror); AIFA observational studies (not public yet); Hungary (OGYÉI) and Vietnam (no official public database found).

Verification and documentation

- [X] Tests per importer with recorded fixtures and `Http::fake()` (first import, repeated import, changes, missing fields, failures); no test calls a live registry.
- [X] Attribution and licence of each source shown where its data appears, and READMEs (all languages), changelog and evaluation document updated.

### Stage 9. Automated testing, quality gates and source health checks

Objective: a complete, repeatable test routine enforced by CI (the existing GitHub Actions workflows, PHPUnit and Vitest), plus a separate scheduled check that verifies every live data source every 12 hours. Pull-request checks never depend on a live source; they use recorded fixtures and fakes.

Inventory

- [ ] Review the architecture, existing tests, CI workflows, importer and scraper adapters and `config/registries.php`; list every enabled source with its normal request and expected response.

Test suite

- [ ] Unit tests: business rules, validation, parsing, normalization, deduplication and error handling.
- [ ] Integration and API tests: entry points, persistence, queues and source adapters, covering success, invalid input, upstream failures, retries and idempotency.
- [ ] Contract tests: the API shapes the web app consumes, and each source's assumptions against representative recorded fixtures, including empty results, missing fields and changed response formats.
- [ ] End-to-end tests with Playwright (no browser test framework exists yet) for the critical journeys (sign-up and login, search, study page, alerts, billing screens with the fake gateway, team and digest screens) on deterministic seeded data. This replaces the browser-test items under Next steps.
- [ ] Smoke tests in CI: the application starts and the essential routes and services (database, Redis, queue) respond; lightweight post-deployment checks if a deployment workflow is added.
- [ ] Regression and acceptance tests: confirmed bugs and documented business rules (free core, entitlements, deduplication, digest rules) as repeatable scenarios.
- [ ] Performance baseline for critical endpoints (search, study page) and ingestion with representative fixtures on project-controlled infrastructure: workload, latency, throughput and error rate recorded; longer runs kept out of pull-request checks.

Defensive security checks

- [ ] Authentication: JWT issue, refresh, expiry and revocation (logout and blacklist), and CSRF protection on any cookie-authenticated route.
- [ ] Authorization for every sensitive operation: authorized user, unauthenticated user, insufficient permission and a user from another team, asserting that rejected writes leave state unchanged.
- [ ] Input validation, protection of server-managed fields (mass assignment), rate limiting, and no credentials, tokens or other teams' data in responses or logs.
- [ ] CI: static security analysis, dependency vulnerability scanning (Composer and npm) and secret scanning; configuration and response-header checks against a project-owned test environment.

Coverage gate

- [ ] CI fails when line, class or method coverage of first-party production code falls below 80% (three independent thresholds). The denominator includes code no test loads (for example source adapters); generated and vendored code is excluded, and every exclusion is documented.
- [ ] Machine-readable coverage report (Clover/Cobertura) as a CI artifact plus a readable job summary; web app function coverage reported separately; exact results and remaining gaps recorded.

Source health check every 12 hours

- [ ] Separate scheduled workflow at 00:00 and 12:00 UTC with a manual trigger running the same command, no overlapping runs (concurrency group), and existing alerting used if configured.
- [ ] For every enabled source, one minimal read-only request through the production adapter or its shared request code, with bounded timeouts and retries within the provider's documented limits.
- [ ] Each check proves the response reached the expected service, has the expected content type or structure and is parsed by the adapter (an HTTP 200 alone is not enough), and tells a valid empty result from an error page or a changed format.
- [ ] Report per source: name, outcome, status code, latency and failure reason, classified as connectivity, authentication, throttling or parsing/schema change. All sources are checked after a failure; the job fails if any required source is unhealthy and reports all results together.
- [ ] No production writes, and no credentials or sensitive response content in logs.
- [ ] Tests of the health-check runner with fixtures for healthy responses, valid empty results, upstream failures, throttling and malformed responses.

Verification and documentation

- [ ] Run every suite and the coverage gates, validate the scheduled workflow configuration, and run the live check once if outbound access and credentials are available; record which sources were verified and which checks were blocked, and why. Report the schedule as configured until GitHub Actions has run it.
- [ ] Document local test commands, coverage report location, scheduled times, the manual trigger and how to add a new source to the health check, in every README, the agent guide and the changelog.

## Next steps

Found while building the stages above.

- [ ] Test the billing flows against Stripe test mode (checkout, swap, cancellation, webhooks). Automated tests use a fake gateway and Cashier's webhook handler without Stripe.
- [ ] Browser tests for the web app (for example with Playwright). The Vue code is type-checked, unit-tested and built, but no end-to-end browser test exists.
- [ ] Switch the Redis client from Predis to the phpredis extension once a phpredis release builds on PHP 8.6 (installed with PIE, since PHP 8.6 images no longer include PECL).
- [ ] Decide whether the queue and sessions should also move to Redis; today only the cache uses it.
- [ ] Drop `@typescript/typescript6`, `scripts/vue-tsc.cjs` and the `glob`/`minimatch` overrides once `vue-tsc` supports TypeScript 7 and `@vue/test-utils` ships patched dependencies.
- [ ] Operator actions for stage 8: set `REGISTRY_CONTACT`, rebuild the app image (`poppler-utils`), run each new importer once against the live registry with `--max-pages=1` and check the stored records, then decide which registries to schedule. Ask for written confirmations: EMA (automated calls to the CTIS portal API and the EU CTR export), DECIT/Fiocruz (ReBEC reuse or the TRDS XML), PPB Kenya, EDA Egypt and the Ukrainian Ministry of Health (commercial reuse), BfArM (DRKS records before 2025). Register keys for MFDS, CRIS and the NCI API.
- [ ] Stage 8 follow-ups found while building the importers: `TextTable` collapses two columns with the same label (the EDA PDF repeats "Study"); about 10 of 89 rows of the EDA July 2026 PDF parse badly because its columns are misaligned; ISRCTN publishes no status, so it is derived from the recruitment and end dates; TFDA has several rows per trial and its sites and drugs files cannot be linked to trials; Health Canada, TFDA, PPB, EDA and MOZ publish no other registry's identifiers, so their trials can appear next to the same study from ClinicalTrials.gov.
- [ ] Operator actions for stage 6: create the Pro AI ($19) and Team AI ($99) monthly USD prices in Stripe, set `STRIPE_PRICE_PRO_AI` and `STRIPE_PRICE_TEAM_AI`, run `php artisan billing:check-prices`, and set the `AI_*` variables. Then test checkout and digests against Stripe test mode and the chosen AI provider; automated tests use fakes for both.
- [ ] Browser tests for the team, saved-search and digest screens, together with the end-to-end tests above.
- [ ] Speed up the test database setup (for example `php artisan schema:dump`); running every migration takes about three minutes on Docker Desktop.
