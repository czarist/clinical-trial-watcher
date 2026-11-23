# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Releases are identified by Git tags in the form `vMAJOR.MINOR.PATCH` and published as GitHub releases. While the version is `0.x`, minor versions may change the API.

## [Unreleased]

### Added

- Roadmap stage 8 (more registries): a multi-registry schema, a scraping base with a legal check per source, and importers planned for ISRCTN, Health Canada, CTIS, EudraCT, DRKS, REEC, CRIS, MFDS, UMIN-CTR, TFDA, ANZCTR, RENIS, ReBEC, NCI, EDA, CDE/NMPA and others, with WHO ICTRP conditioned on WHO's confirmation of its terms.

## [0.1.0] - 2026-11-23

First release.

### Added

- READMEs in Spanish, Simplified Chinese, Traditional Chinese, Japanese and Korean, next to the English and Brazilian Portuguese ones, linked from each other.
- Internationalization: the web app, API messages (validation, authentication, business errors), emails and AI digests in English (default and fallback), Brazilian Portuguese, Spanish, Simplified Chinese, Traditional Chinese, Japanese and Korean. The API answers in the `Accept-Language` language and sends `Content-Language`; accounts store a `locale` (set at registration, changed with `PUT /api/account/locale`) used for their emails; AI digests can be written in all seven languages, with localized factual fallbacks and dates and advice/efficacy checks for each language. The web app has a language menu, starts in the browser's language and formats dates, numbers and prices for the chosen language. Tests check that every language has every key and placeholder.
- Free core with paid AI digests. New plans in USD: Free ($0), Pro AI ($19/month) and Team AI ($99/month per team). Search, study details, change history, saved searches and basic alerts are free on every plan.
- AI digests for saved searches: daily or weekly, in English or Brazilian Portuguese, turned on or off without touching the basic alert. The backend counts and ranks the matching changes from the saved-search criteria and links every study to ClinicalTrials.gov; the model (any OpenAI-compatible API, `config/ai.php`) only words factual summaries from public registry facts, and its JSON answer is validated. Invalid output, provider failures after bounded retries and a disabled provider fall back to a factual summary, recorded on the edition. Summaries are cached per change version, language and prompt version. Editions have a stable identity and one delivery per recipient; `digests:dispatch daily|weekly` is scheduled at 07:30.
- Team AI workspaces: one team per subscriber, invitations of registered users by email with explicit acceptance, member removal and leaving, shared saved searches managed by the owner, team digests to members who opted in, and a report page for each team digest.
- Structured change tracking: site-level recruitment status, a snapshot of tracked facts per study, before-and-after details for overall status, sites added, removed or updated, key dates (original precision kept) and posted results, and a stable fingerprint per change. Reordered site lists are not a change.
- `php artisan billing:check-prices` links the configured Stripe prices (`STRIPE_PRICE_PRO_AI`, `STRIPE_PRICE_TEAM_AI`) to the plans and checks amount, currency and interval; checkout refuses a mismatched price.
- Web app: plan cards with USD prices, AI digest controls with a preview and upgrade action for free users, two sample digest editions (`/digests/samples`), digest reports, and team pages.
- `GET /api/admin/digests` and `GET /api/admin/plans` for operators.
- Search API: `GET /api/trials` with pagination and filters for text (MySQL full-text), condition, keyword, MeSH term, status, phase, study type, sponsor, country, city, dates, results and distance; `GET /api/trials/{nct_id}` with every related list, PubMed links and recorded status changes.
- Alerts: saved searches limited by the plan's `alerts_limit`, matched after each import, with instant, daily or weekly emails and optional status-change notices (`alerts:send-digests`).
- Billing with Stripe through Laravel Cashier: checkout, plan changes with proration, cancellation, resuming, customer portal, a 14-day signup trial, and webhooks that keep the account's plan in sync.
- Registration, email confirmation, token refresh, password reset, profile and password changes, and a rate limit on login.
- Permission middleware on every protected route, and administration endpoints for users, plans, roles and imports, with the new `manage_imports` permission.
- Vue 3 web app (Vue Router, Pinia, TypeScript) served by Laravel: search, study pages, alerts, account and plan, and administration.
- Import runs (`import_runs`) and study changes (`clinical_trial_changes`), a queued import job scheduled daily, and `queue` and `scheduler` Docker services.
- Redis 8.10 service as the cache store (also used by rate limiting and the JWT blacklist), through the Predis client.
- `clinicaltrials:fetch` options `--since`, `--full`, `--max-pages` and `--queue`.
- `primary_completion_date` and `has_results` are stored; search indexes and a full-text index on titles and summary.
- Model factories for the clinical trial tables, alerts and users; PHPUnit tests for the API, the import (with a recorded API response and `Http::fake()`), alerts, billing and administration; Vitest tests for the web app; a GitHub Actions workflow.
- `Packages` workflow that audits the Composer and npm lock files, with *tests* and *packages* status badges in both READMEs.
- Evaluation of other registries (WHO ICTRP, EU CTR/CTIS, ReBEC) in `docs/registries-evaluation.md`, and a project logo.

- English and Brazilian Portuguese READMEs, a roadmap, this changelog, and an `AGENTS.md` guide.
- `php artisan clinicaltrials:fetch` imports studies from the ClinicalTrials.gov API v2. `--pageSize` sets how many studies are read (default 100). Studies are matched by NCT number, so re-running the import updates existing records, and related lists are replaced with the current version from the API.
- Partial dates from the API (`YYYY`, `YYYY-MM`) are stored as full dates.
- Clinical trial tables for secondary identifiers, collaborators, publications and MeSH terms, and new study fields: organization study ID, status verified date, detailed description, eligibility criteria, minimum and maximum age, sex, first posted and last updated dates, acronym, responsible party type, FDA-regulated drug and device flags, expanded access, IPD sharing, and the raw API response.
- JWT authentication with `tymon/jwt-auth`: `POST /api/auth/login`, `POST /api/auth/logout` and `GET /api/auth/me`, with the `api` guard using the `jwt` driver.
- Clinical trial data model: studies with conditions, keywords, locations, interventions, arm groups and outcomes.
- Roles, permissions, plans and subscriptions, with a seeder for the Basic, Pro and Enterprise plans, the `Admin` and `User` roles, their permissions, and an administrator account.
- Laravel 11 application with a Docker Compose stack: PHP 8.3 FPM, MySQL 8.0, nginx and a Vite dev server. The `app` container waits for MySQL, installs dependencies, generates the app key and runs migrations on start.

### Changed

- New accounts start on Free without the 14-day trial (`TRIAL_DAYS` removed). One saved-search limit for every plan (`SAVED_SEARCH_LIMIT`, default 100) replaces the per-plan `alerts_limit`; saved searches above it are kept.
- Basic, Pro and Enterprise are legacy plans: no longer offered or seeded on new installs and without AI features. Their subscriptions, prices, Stripe price IDs and customers are unchanged, and their subscribers can still cancel, resume, use the portal or move to a current plan.
- Alert matching skips changes already matched, so a retried job does not duplicate matches or notifications.
- TypeScript 7 checks the web app's `.ts` files; `vue-tsc` checks the `.vue` files on the official TypeScript 6 package (`@typescript/typescript6`), because TypeScript 7 no longer has the JavaScript compiler API. `npm run typecheck` runs both.
- npm audit reports no vulnerabilities: `picomatch` updated, and `glob` and `minimatch` overridden with patched versions for `@vue/test-utils`.
- The cache moved from the database to Redis (`CACHE_STORE=redis`, Predis client, Redis database 1). The queue and sessions still use the database.
- The GitHub Actions workflow runs on pushes and pull requests to `main`, on tags, and by hand; pushes to `dev` and `hom` do not start it. Branches flow from `dev` to `hom` to `main`.
- Upgraded to PHP 8.6, Laravel 13, PHPUnit 13, Guzzle 8, Tinker 3, MySQL 8.4 LTS, Node.js 26 and Vite 8, with every other dependency on its latest release.
- The import uses the production endpoint `https://clinicaltrials.gov/api/v2/studies`, verifies TLS certificates, follows every page, retries rate limits and server errors with backoff, and by default reads only studies updated since the last successful run. Each study is saved in its own transaction.
- Related lists are always replaced, so an empty list from the API removes the old rows.
- The import logic moved from the command into services (`app/Services/ClinicalTrials/Import`); the command keeps its name and `--pageSize`.
- A failed login returns HTTP 401 instead of HTTP 200 with `status_code` in the body. Logout returns 204.
- `subscriptions` adapted to Cashier: `payment_provider_id` renamed to `stripe_id`, `status` to `stripe_status`; `plan_id` is now nullable.
- Long free-text columns (outcome measure and time frame, intervention name, arm label, facility, reference URL) widened to `TEXT`.
- Composer package and `APP_NAME` renamed from the Laravel skeleton defaults; `.env.example` has the Docker database values, `JWT_SECRET` and the Stripe settings. Host ports are configurable.

- Clinical trial models moved to `App\Models\ClinicalTrials`.
- Long text fields of `clinical_trials` widened: `official_title` and `brief_summary` to `TEXT`, and `detailed_description`, `eligibility_criteria` and `raw_json` to `LONGTEXT`, so long API values are no longer truncated.

### Fixed

- `ClinicalTrialMeshTerm` had the namespace `App\Models\clinicalTrials`, which broke PSR-4 autoloading.
- Plan `features` were JSON-encoded twice by the seeder.
- Expected API errors, such as invalid credentials, are no longer written to the log, and the container keeps `storage/` writable by PHP-FPM.
- Vitest failed in CI because `laravel-vite-plugin` refuses to start when the `CI` variable is set; the plugin is now skipped while Vitest runs.

### Removed

- Laravel Sanctum; the API uses JWT only.
- The framework's example tests and welcome page.

[Unreleased]: https://github.com/czarist/clinical-trial-watcher/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/czarist/clinical-trial-watcher/releases/tag/v0.1.0
