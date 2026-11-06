# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Releases will be identified by Git tags in the form `vMAJOR.MINOR.PATCH`; no version has been released yet.

## [Unreleased]

### Added

- English and Brazilian Portuguese READMEs, a roadmap, this changelog, and an `AGENTS.md` guide.
- `php artisan clinicaltrials:fetch` imports studies from the ClinicalTrials.gov API v2. `--pageSize` sets how many studies are read (default 100). Studies are matched by NCT number, so re-running the import updates existing records, and related lists are replaced with the current version from the API.
- Partial dates from the API (`YYYY`, `YYYY-MM`) are stored as full dates.
- Clinical trial tables for secondary identifiers, collaborators, publications and MeSH terms, and new study fields: organization study ID, status verified date, detailed description, eligibility criteria, minimum and maximum age, sex, first posted and last updated dates, acronym, responsible party type, FDA-regulated drug and device flags, expanded access, IPD sharing, and the raw API response.
- JWT authentication with `tymon/jwt-auth`: `POST /api/auth/login`, `POST /api/auth/logout` and `GET /api/auth/me`, with the `api` guard using the `jwt` driver.
- Clinical trial data model: studies with conditions, keywords, locations, interventions, arm groups and outcomes.
- Roles, permissions, plans and subscriptions, with a seeder for the Basic, Pro and Enterprise plans, the `Admin` and `User` roles, their permissions, and an administrator account.
- Laravel 11 application with a Docker Compose stack: PHP 8.3 FPM, MySQL 8.0, nginx and a Vite dev server. The `app` container waits for MySQL, installs dependencies, generates the app key and runs migrations on start.

### Changed

- Clinical trial models moved to `App\Models\ClinicalTrials`.
- Long text fields of `clinical_trials` widened: `official_title` and `brief_summary` to `TEXT`, and `detailed_description`, `eligibility_criteria` and `raw_json` to `LONGTEXT`, so long API values are no longer truncated.
