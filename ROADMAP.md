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

- [ ] Multi-registry schema through new migrations: `source` and `source_id` on studies, keeping `nct_id` working for ClinicalTrials.gov, and a table of linked identifiers (NCT, ISRCTN, EudraCT, EU CT, DRKS, REEC, CRIS, UMIN, jRCT, ACTRN, RBR, UTN and others).
- [ ] Importer contract per source (fetch, map to the common study model, incremental cursor, `ImportRun` per source) and a `--source` option on the import command, keeping `clinicaltrials:fetch` compatible.
- [ ] Deduplication through normalized secondary identifiers, never titles: records of the same study are linked as reviewable matches, keeping each source's conflicting values attributed to it.
- [ ] Search, study page, alerts and digests aware of the source (filter by registry, link to the source record, source shown on every item).

Scraping (for sources without an API or export)

- [ ] Scraping importer base: an identified user agent with a contact address, `robots.txt` honoured, per-source rate limit and concurrency of one, bounded retries with backoff, conditional requests (`ETag`/`Last-Modified`) and a raw-page cache so a parser fix does not refetch.
- [ ] Legal gate per source before any scraper ships: terms of use and `robots.txt` checked and recorded in the evaluation document; never bypass blocks, logins, captchas or rate limits; when automated access is refused or blocked, ask the operator for permission or an export instead.
- [ ] Parsers kept separate from fetching: HTML listings and detail pages (selectors per source, with recorded fixtures), and PDF tables (text extraction, then table parsing) for registries that publish only PDFs.
- [ ] Change detection for scraped records (content hash of the normalized record), and layout-drift alerts: a run that parses far fewer records or fields than the last one is marked `partial` and logged instead of deleting data.
- [ ] Keep the raw page or file of each scraped record (like `raw_json`) so fields can be remapped without fetching again.

Importers

- [ ] ISRCTN (international): XML API and CSV; contribution licence allows commercial use.
- [ ] Health Canada Clinical Trials Database: JSON/XML API, open licence with attribution; phase I–III drug and biologic trials.
- [ ] CTIS (EU/EEA): search CSV and per-trial ZIP with XML/PDF; confirm with EMA whether the portal endpoints may be used programmatically.
- [ ] EU Clinical Trials Register / EudraCT: portal download of the historical archive, which still receives results for EudraCT trials; at least link records through EudraCT numbers.
- [ ] DRKS (Germany): JSON, CSV and RIS per study and ZIP exports of several records.
- [ ] REEC (Spain): REST extraction service documented by AEMPS.
- [ ] CRIS (South Korea): official open API on data.go.kr for listing, details and statistics (needs a service key).
- [ ] UMIN-CTR (Japan): compressed CSV updated daily; the Japanese version is complete, the English one a subset.
- [ ] TFDA Drug Clinical Trial Status (Taiwan): CSV on the open data portal with protocol, phase, criteria and trial status.
- [ ] ANZCTR (Australia and New Zealand): ZIP/XML of full records from search; confirm the conditions of the SOAP service.
- [ ] RENIS (Argentina): historical CSV on the open data portal; the current registry is queried in SISA.
- [ ] ReBEC (Brazil): per-trial WHO TRDS XML; ask Fiocruz about bulk access or permitted crawling.
- [ ] WHO ICTRP (international): CSV/XML and institutional access to new and updated records. Its terms (section 4(d)) forbid using extracted data "for marketing, promotional or commercial purposes", without defining them. Before importing, ask WHO for written confirmation through its permissions form that free display with attribution alongside paid AI digests is allowed. Until then, ICTRP-only records stay out of paid features (AI digests), carry the "WHO ICTRP" attribution, and the registries ICTRP aggregates are imported from their own sources where their licences allow it.
- [ ] MFDS approved trial plans (South Korea, data.go.kr `ClncExamPlanDtlService03`): REST API (JSON/XML) with a free key; drug trials authorized by MFDS with their approval number; the portal states no usage restriction. MFDS also has a COVID-19 trials API.
- [ ] NCI Clinical Trials Search API v2 (US, cancer): REST API with a free key; ClinicalTrials.gov and CTRP records with biomarkers and structured eligibility, so enrich existing NCT studies instead of duplicating them; confirm the terms.
- [ ] EDA clinical trials registry (Egypt): periodic PDF (latest 07/2026) with phase, sites, status and sponsor; needs the PDF table parser. Data no other mapped registry seems to cover.
- [ ] CDE/NMPA drug trial registration and disclosure platform (China, chinadrugtrials.org.cn): every drug trial approved in China, separate from ChiCTR. Web search only and the site blocked automated access, so scrape only after the terms are confirmed and access is allowed; otherwise request an export.
- [ ] HMA-EMA catalogue of real-world data studies (EU, replaces the EU PAS Register): observational and post-authorisation studies; web search, API and terms to verify.
- [ ] HRA Research Summaries (UK): every study reviewed by a UK ethics committee (~69,000), with results summaries; paged web pages, no download or API, © HRA, so scrape only with permission.
- [ ] RNEC (Portugal, INFARMED): trials authorized in Portugal (transitioned to CTIS and Directive trials until 01/2025); web search without export; link to CTIS records where they overlap.
- [ ] INCa cancer trials registry (France): oncology trials in France; web search; terms to verify.
- [ ] Orphanet research and clinical trials (rare diseases): trials with ORPHA codes, partly from WHO ICTRP; web search, and Orphadata does not confirm trials are included; use ORPHA codes to enrich conditions.
- [ ] Plataforma Brasil (CEP/Conep): every human-research project reviewed by an ethics committee; public search of approved projects, no API; scrape only after the terms are confirmed.
- [ ] TİTCK Clinical Research Portal (Turkey, kap.titck.gov.tr): trials authorized by TİTCK; web search, API to verify.
- [ ] MOZ orders authorizing trials and amendments (Ukraine): lists in PDF annexes per order; PDF parser. Replace with the State Register run by the State Expert Center once its public address is found.
- [ ] Pharmacy and Poisons Board trials system (Kenya): approved trials with investigators, status, sites and objectives; web search; confirm the subsystem address and terms.
- [ ] SÚKL clinical trials database (Czechia): trials authorized since 2007, excluding bioequivalence and first-in-human; new trials are in CTIS; web search.
- Not planned: EMA Clinical Data Publication (terms allow only non-commercial use, login required; link to it only); Australian Clinical Trials and Australian Cancer Trials (copies of ANZCTR); KDCA registration statistics (from CRIS); AACT (a ClinicalTrials.gov mirror); AIFA observational studies (not public yet); Hungary (OGYÉI) and Vietnam (no official public database found).

Verification and documentation

- [ ] Tests per importer with recorded fixtures and `Http::fake()` (first import, repeated import, changes, missing fields, failures); no test calls a live registry.
- [ ] Attribution and licence of each source shown where its data appears, and READMEs (all languages), changelog and evaluation document updated.

## Next steps

Found while building the stages above.

- [ ] Test the billing flows against Stripe test mode (checkout, swap, cancellation, webhooks). Automated tests use a fake gateway and Cashier's webhook handler without Stripe.
- [ ] Browser tests for the web app (for example with Playwright). The Vue code is type-checked, unit-tested and built, but no end-to-end browser test exists.
- [ ] Switch the Redis client from Predis to the phpredis extension once a phpredis release builds on PHP 8.6 (installed with PIE, since PHP 8.6 images no longer include PECL).
- [ ] Decide whether the queue and sessions should also move to Redis; today only the cache uses it.
- [ ] Drop `@typescript/typescript6`, `scripts/vue-tsc.cjs` and the `glob`/`minimatch` overrides once `vue-tsc` supports TypeScript 7 and `@vue/test-utils` ships patched dependencies.
- [ ] Operator actions for stage 6: create the Pro AI ($19) and Team AI ($99) monthly USD prices in Stripe, set `STRIPE_PRICE_PRO_AI` and `STRIPE_PRICE_TEAM_AI`, run `php artisan billing:check-prices`, and set the `AI_*` variables. Then test checkout and digests against Stripe test mode and the chosen AI provider; automated tests use fakes for both.
- [ ] Browser tests for the team, saved-search and digest screens, together with the end-to-end tests above.
- [ ] Speed up the test database setup (for example `php artisan schema:dump`); running every migration takes about three minutes on Docker Desktop.
