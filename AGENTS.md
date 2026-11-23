# Agent guide

Guidelines for contributors and coding agents working on Clinical Trial Watcher.

## 1. Start here: workflow for every task

Follow these steps in order. Do not start by editing files.

1. Read the user's request and identify the expected result. A request to fix one bug is not permission to implement the whole roadmap.
2. Run `git status --short`. Existing modified and untracked files may belong to the user. Preserve them; do not reset, delete, overwrite or commit them as unrelated cleanup.
3. Read this guide, then any more specific `AGENTS.md` in the directories you will change.
4. Read `README.md` for current behavior and setup. Read the relevant part of `ROADMAP.md` for known gaps. Read `CHANGELOG.md` before adding a release note.
5. Locate the implementation with `rg` and `rg --files`. Read the actual files, their callers, models, migrations and relevant tests before choosing a solution. Never invent a class, column, route, method or configuration key because it sounds plausible.
6. Load only the skills needed for the task; see the skill table below.
7. Identify the smallest complete change: implementation, schema if needed, regression coverage, and documentation if behavior changes.
8. Implement it using the recipes below. Follow nearby conventions unless they are a known bug or conflict with this guide.
9. Run the applicable verification commands. Read failures and fix failures introduced by your change. Do not hide a failure by deleting a test or weakening an assertion.
10. Review the final diff and status. Remove only accidental changes that you introduced.
11. Report what changed, what was verified, and anything still blocked. Never say tests passed if they were not run successfully.

Proceed with ordinary implementation choices supported by the repository. Ask only when missing information materially changes the required behavior or an action would destroy data without authorization. If a tool or service is unavailable, complete the independent work and report the precise limitation.

## 2. Non-negotiable boundaries

- Keep the task scoped. Do not add dependencies, upgrade Laravel, replace authentication, redesign the architecture or implement extra roadmap items unless the task requires it.
- Keep new application code, identifiers and comments in English. Reply to the user in the language they use.
- Never commit `.env`, credentials, JWTs, private keys, database dumps or real user data. Do not print secrets in logs or command output.
- Do not modify generated or third-party code in `vendor/` or `node_modules/`.
- Do not run `git reset --hard`, `git clean`, database resets, `migrate:fresh`, `migrate:refresh`, `db:wipe` or `docker-compose down -v` against the working environment without explicit authorization to discard that data.
- A setup example in the README is not authorization to erase an existing database.
- Do not change a shipped migration to make a schema change. Add a new migration.
- Do not disable TLS checks, authentication or validation to make a failing request succeed.
- Do not represent clinical registry data as a treatment recommendation or patient eligibility decision.
- Do not claim a planned feature exists. Checked roadmap items must reflect implemented and verified work.

## Project context

Clinical Trial Watcher is a Laravel 13 application (PHP 8.6, MySQL 8.4, Redis cache through Predis) that imports clinical studies from the ClinicalTrials.gov API v2, serves them through an API with JWT authentication, and ships a Vue 3 web app in `resources/js/`. It runs with Docker Compose. Keep new code in the layer it belongs to:

- `app/Console/Commands/FetchClinicalTrials.php` is the import's CLI entry point; the work is in `app/Services/ClinicalTrials/Import/` (`StudyMapper` maps the API's `protocolSection` and `derivedSection` modules, `ClinicalTrialSynchronizer` persists). Records are matched by `source` and `source_id` (the registry and the study's identifier there; for ClinicalTrials.gov the NCT number, also kept in `nct_id`); keep imports idempotent so a run can be repeated safely. Each registry is a `RegistrySource` listed in `config/registries.php` (`app/Services/Registries/`).
- `app/Models/ClinicalTrials/` holds `ClinicalTrial` and one model per related list. Every related table has a `clinical_trial_id` foreign key and a `hasMany` relation on `ClinicalTrial`. When you add a field, add it to the migration, the model's `$fillable`, and the import mapping; a field missing from `$fillable` is silently dropped.
- Keep the full API response in `raw_json` so new fields can be mapped later without fetching again.
- `app/Models/` holds `User`, `Role`, `Permission`, `Plan` and `Subscription`. `User` implements `JWTSubject`.
- Controllers stay thin: `app/Http/Controllers/Api/` validates with Form Requests in `app/Http/Requests/` and delegates to services in `app/Services/`. Put new business logic in services, not in controllers or commands.
- API routes live in `routes/api.php` under the `/api` prefix. Protected routes use `auth:api` (the JWT guard in `config/auth.php`).
- The schema is defined only by `database/migrations/`. Add a new migration for every change; never edit a migration that has already run.
- Seeders in `database/seeders/` use `updateOrCreate` so they can run more than once. Keep them that way.
- Study data is public registry data, not medical advice. Do not add features that present it as a recommendation for a patient.

See [README.md](README.md) for user-facing behavior and setup, [ROADMAP.md](ROADMAP.md) for planned work and known issues, and [CHANGELOG.md](CHANGELOG.md) for notable changes.

## 3. Where to put each change

| Responsibility | Location | Rule |
|---|---|---|
| API URL, HTTP verb and middleware | `routes/api.php` | Laravel already adds `/api`; do not add it again to route paths. |
| Input validation and request authorization | `app/Http/Requests/` | Use a Form Request; pass validated input onward. |
| HTTP response and service call | `app/Http/Controllers/Api/` | Keep controllers thin; set the actual HTTP status. |
| Business rules and reusable operations | `app/Services/` | Put new business logic here. |
| Eloquent attributes, casts and relationships | `app/Models/` | Keep schema and mass-assignment rules aligned. |
| Clinical study models | `app/Models/ClinicalTrials/` | Each child row belongs to a study through `clinical_trial_id`. |
| Schema, foreign keys and indexes | `database/migrations/` | Add a new migration with a matching rollback. |
| Repeatable initial data | `database/seeders/` | Use stable keys with `updateOrCreate`. |
| Import CLI entry point | `app/Console/Commands/FetchClinicalTrials.php` | Preserve the command interface; delegate new business logic to services. |
| Scheduled command registration | `routes/console.php` | The daily import and the alert digests are scheduled here. |
| API/import integration tests | `tests/Feature/` | Test observable behavior and persisted results. |
| Isolated logic tests | `tests/Unit/` | Keep pure unit tests independent of HTTP and the database. |
| JWT guard and token configuration | `config/auth.php`, `config/jwt.php` | Protected API routes use `auth:api`. |

Commands stay thin like controllers: parse options, call a service, report the result.

## 4. Current behavior versus known gaps

This is a navigation aid, not a second roadmap. Confirm these points in code before acting; update this section when your task fixes one of them.

| Area | Current implementation / trap |
|---|---|
| API | Auth, account, search, alerts, billing and admin routes exist; see the README. Responses use API Resources; business failures throw an `ApplicationException` subclass that sets the HTTP status. |
| Authentication | JWT only (`auth:api`). Sanctum was removed; do not reintroduce it. |
| Authorization | Protected routes use the `permission:<name>` middleware (`App\Enums\PermissionName`) and policies for ownership. A new protected route needs both where relevant. |
| Import | `ClinicalTrialImporter` follows every page, retries with backoff, saves each study in its own transaction and records an `ImportRun`. Incremental runs start from the last `succeeded` run; a run stopped by `--max-pages` or with failed studies is `partial` and does not move that point. |
| Duplicates | `DuplicateLinker` runs in each study's transaction: it indexes identifiers, links records that share one and sets `canonical_trial_id` on every record but the one shown. Same-registry matches and groups holding one registry twice become `conflict` links for review (`/api/admin/trial-links`). Searches exclude non-canonical records (`CanonicalFilter`); alerts and digests count a study once. A new registry needs a `RegistrySource`, an entry in `config/registries.php`, tests with recorded fixtures, and its record URL validated by the source. |
| Related lists | `StudyMapper` always returns every list in `ClinicalTrial::SYNCED_RELATIONS`; the synchronizer replaces each one. A new related list must be added to both. |
| Full-text search | InnoDB full-text indexes do not see uncommitted rows, so a test of `q` must commit its data (see `FullTextSearchTest`) and clean it up. |
| Billing | Plans are Free, Pro AI and Team AI (USD per month); Basic, Pro and Enterprise are legacy (`is_legacy`) and must stay untouched for existing subscribers. Check paid features through `App\Services\Billing\Entitlements`, never by plan name. Core search and basic alerts must never need a paid plan. Stripe is reached only through `SubscriptionGateway`; tests bind `Tests\Fakes\FakeSubscriptionGateway`. Webhooks are handled by Cashier at `/stripe/webhook`. |
| Teams | A team has one owner (the Team AI subscriber) and members who accepted an invitation. Check membership with `TeamPolicy`/`Team::hasMember()` on every team route, and the workspace with `Entitlements::teamIsActive()`. |
| Changes | `ClinicalTrialSynchronizer` compares `TrackedFacts` snapshots with `ChangeDetector` and writes `details` and a unique `fingerprint` in the study's transaction. A study without a snapshot gets a baseline, not invented details. Dates keep their original precision in digests. |
| AI digests | Provider calls happen only in `GenerateDigestJob`, never in a request or the import transaction, and only through `AiProvider`; tests bind `Tests\Fakes\FakeAiProvider`. Send only public registry facts. Keep `SummaryValidator` strict and the factual fallback working with AI disabled. Change `ai.prompt_version` when the prompt changes. |
| Languages | Offered languages are `App\Enums\Locale` on the backend and `LOCALES` in `resources/js/i18n/index.ts`; keep both lists equal. Never hard-code user-facing text: add the English message to `lang/en/*.php` (API, email and digest text) or `resources/js/i18n/locales/en.ts` (web app) and the same key, with the same placeholders, to every other language — `TranslationCatalogTest`, `i18n.spec.ts` and the type checker fail otherwise. Framework mail strings live in `lang/<locale>.json`. Advice and efficacy wording must be added to `SummaryValidator` for every language. Registry data itself is not translated. |
| Tests | PHPUnit runs on the dedicated MySQL database `clinical_saas_test` (forced in `phpunit.xml`). Running every migration takes minutes on Docker Desktop. Use `actingAsApiUser()` to switch users within a test: it clears the token cached by tymon/jwt. |

Do not silently fix every item while doing an unrelated task. If a known defect affects the requested result, include the necessary fix and coverage; otherwise mention it only when relevant.

## 5. Recipe: add or change an imported study field

Complete the entire path from source data to persisted value:

1. Find the exact source field in a recorded response or verified API schema. Note its module, type, whether it can be absent, and whether it can be `null`.
2. Inspect existing migrations and the target model. Do not assume a field exists just because the importer assigns it.
3. Add a new migration for the column. Choose type, nullability and any default from the data contract. Existing rows must remain valid.
4. Add the attribute to the model's `$fillable` when using `create`, `update` or `updateOrCreate`. Add an appropriate cast if needed; check the existing representation first.
5. Add the mapping. Handle optional modules without undefined-index errors. Preserve `false` and `0`; neither automatically means missing data.
6. Preserve the full source study in `raw_json`. It is the individual study payload, not merely the subset of mapped fields. Check casts before encoding so the payload is not JSON-encoded twice.
7. If the field is exposed by an endpoint, update its response/resource and validation/filtering only as required by the task.
8. Add a test that imports a fixture and reads the value back from the database. An assertion on a temporary PHP array does not prove persistence.
9. Cover an absent/null source field and an update to an existing study where relevant.
10. Update the roadmap/changelog and all READMEs if user-facing behavior changes.

For a new related list, also add its table, child model, `clinical_trial_id` foreign key and `hasMany` relation on `ClinicalTrial`. Follow existing naming and foreign-key conventions. Ensure child attributes are fillable and test that rows belong to the correct study.

## 6. Recipe: change the import safely

- Keep `clinicaltrials:fetch` and existing options compatible unless the user requests a change.
- Match records by `source` and `source_id`, not title or array position. Reject or handle missing identifiers explicitly; do not create anonymous duplicates.
- Link duplicate registrations only through normalized registry identifiers (`IdentifierParser`, `DuplicateLinker`), never titles or sponsor protocol numbers. Keep each registry's values on its own record, never overwrite one registry's data with another's, and never override a link a person confirmed or rejected.
- Idempotent means importing the same study twice produces one parent and the expected current child rows, not twice as many children.
- A provided empty list means there are no current items: relation synchronization must remove obsolete rows. Define the treatment of an absent module separately if the source can return partial payloads.
- Scope child deletion to the current study, for example through its relationship. Never delete all rows in a related table to refresh one study.
- When implementing atomic synchronization, put the parent and its child writes in one transaction per study. Fetch remote data outside that transaction.
- Preserve date normalization unless the task changes it: `2024` becomes `2024-01-01`, `2024-06` becomes `2024-06-01`, and full dates stay full dates. These are normalized values, not evidence the registry supplied that exact day.
- When implementing pagination, follow `nextPageToken` until absent. Test multiple pages; increasing `pageSize` is not pagination.
- Handle failed responses explicitly. Do not report success after discarding a network or persistence error. Keep retries bounded if retries are part of the task.
- Test with `Http::fake()` and local, recorded study fixtures. Use `Http::preventStrayRequests()` to catch requests that escape the fake. Tests must never call ClinicalTrials.gov.

For import changes, choose applicable regression cases: first import, identical second import, changed data, an empty related list, missing optional modules, partial dates, HTTP failure, multiple pages, and rollback after a child write fails. Test the cases your change affects; do not pretend unimplemented features already work.

## 7. Recipe: add or change an API endpoint

1. Inspect `routes/api.php`, an existing controller, its Form Request and its service.
2. Establish the HTTP method, URL, input, output, status codes and access rules before implementing.
3. Register the route in `routes/api.php`. Put `auth:api` on protected routes. Do not use `auth:sanctum` for these routes.
4. Validate input with a Form Request. Pass validated fields explicitly; do not mass-assign `$request->all()`.
5. Put business rules in a service. Keep the controller responsible for delegation and the HTTP response.
6. For protected operations, check ownership/permissions as required. Being logged in does not automatically authorize access to another user's records.
7. Return the correct HTTP status, not only a `status_code` field in the body. Preserve existing response contracts unless the task requires changing them.
8. For list endpoints, paginate results and bound client-controlled limits. Allowlist sortable/filterable fields; never interpolate arbitrary input into SQL. Eager-load relations that will be serialized to avoid one query per result.
9. Test the successful response and applicable invalid-input, unauthenticated, forbidden and missing-resource cases. Assert HTTP status and meaningful response fields.
10. Document changed public routes and behavior in `README.md` and in every translated README (`README.pt-BR.md`, `README.es.md`, `README.zh-CN.md`, `README.zh-TW.md`, `README.ja.md`, `README.ko.md`).

Never expose password hashes, tokens or internal exception details in responses. The seeded administrator credentials are for local development only.

## 8. Recipe: migrations and seeders

1. Inspect the original table migration and all later migrations touching that table.
2. Create a new migration. Use `Schema::table` for an existing table; do not recreate it to add a column.
3. Make `up()` apply only the intended change and `down()` reverse only that change. Account for foreign keys and existing rows.
4. Keep the model, import and validation consistent with the resulting schema.
5. Verify migrations on a dedicated disposable test database. Do not use the user's working database for rollback/reset experiments.
6. Keep seeders repeatable with stable lookup keys in `updateOrCreate`. Running the same seeder twice must not duplicate roles, permissions or plans.

Do not execute schema changes against an unknown database. Check the target environment first without exposing credentials.

## Shared agent skills

This file is the shared project guide. Keep common agent instructions here instead of copying them into per-agent configuration files.

The canonical workspace skills live in `.agents/skills/<name>/SKILL.md`. Every agent uses this one directory; agents that support the shared Agent Skills convention discover the skills there.

- Install skills with the [`skills`](https://skills.sh/) CLI as copied files, targeting only `.agents/skills/`: `npx skills add <owner/repo> --skill <name> -a codex --copy -y`. `skills-lock.json` records their sources; run `npx skills update -p` to refresh them.
- The only exception is `.claude/skills/`, which holds symbolic links to `.agents/skills/` because Claude Code reads skills only from there. Do not put files there; it must contain only those links.
- Do not install into any other per-agent directory (`.cursor/`, `.roo/`, `.windsurf/`, `.kiro/` and the like), into a `skills/` folder at the repository root, or into `data/skills/`, and do not create copies or links there. If the CLI creates them, delete them.
- Do not edit the installed skill files by hand; they are overwritten on update. Put project-specific guidance in this file instead.

Skills are specialized references, not always-on instructions. Load a skill only when its task-specific guidance is useful.

| Skill | Use for |
|---|---|
| `laravel-specialist` | Laravel features: Eloquent models and relations, migrations, queues and scheduled jobs for the import, API resources, JWT auth flows. This is the primary skill for this project. |
| `php-pro` | Modern PHP 8.3 code, strict typing, PSR standards, DTOs and value objects, PHPStan or Psalm. |
| `php-best-practices` | SOLID principles (one rule per principle), PSR standards and PHP 8.x patterns when designing or reviewing classes and services. |
| `api-designer` | Designing the REST API: resource modeling, pagination, filtering, versioning, error formats and OpenAPI specs. |
| `database-optimizer` | MySQL indexes, slow queries and execution plans, such as the search filters over the clinical trial tables. |
| `secure-code-guardian` | Authentication, authorization, JWT handling, input validation and OWASP checks. |
| `test-master` | Test strategy and test files: PHPUnit feature tests for the API, import tests with faked HTTP responses, coverage gaps. |
| `devops-engineer` | Dockerfile, Docker Compose, CI pipelines such as GitHub Actions, and deployment. |
| `monitoring-expert` | Logging, metrics and alerts for the scheduled import and the API. |
| `code-documenter` | PHPDoc, OpenAPI documentation and contributor guides. |
| `frontend-design` | A distinct visual direction for the planned web interface. Only needed for front-end work. |

Some skills overlap. Use `laravel-specialist` for framework code and `php-pro` only for language-level questions outside Laravel; use `api-designer` for the shape of the API and `laravel-specialist` to implement it. Combine skills only when the task has separate needs they each address.

## 9. Commands and verification

Run commands from the repository root. Run PHP, Artisan, Composer and Pint inside the `app` container, not with the host PHP installation. Source edits are visible in the container through the mounted repository.

The commands below use `docker-compose`, matching this repository. If only Compose v2 is installed, use `docker compose` with the same arguments. For noninteractive execution, `exec -T` disables TTY allocation.

If your environment loads `C:\Users\lucas\.codex\RTK.md`, follow its command-prefix rule. For example, use `rtk git status --short` or `rtk proxy docker-compose exec -T app php artisan test`. The commands below show the underlying commands for agents without that wrapper.

Inspect before changing:

```sh
git status --short
git diff --stat
docker-compose ps
docker-compose exec -T app php artisan route:list --path=api
```

For application changes, run the relevant test while developing and then the full suite:

```sh
docker-compose exec -T app php artisan test --filter=YourTestClass
docker-compose exec -T app php artisan test
```

Replace `YourTestClass` with an actual test class or method. Before running tests that migrate, refresh or write to a database, verify the effective test connection uses a dedicated test database. Configure it if needed for the task. Do not assume the `testing` environment name makes the normal database safe to reset.

Format changed PHP files with Pint, then check formatting:

```sh
docker-compose exec -T app ./vendor/bin/pint --dirty
docker-compose exec -T app ./vendor/bin/pint --test
git diff --check
git diff
git status --short
```

`--dirty` can include pre-existing user changes and may not cover new untracked files. Prefer explicit paths when the working tree already contains unrelated edits or when adding files, for example `./vendor/bin/pint app/Services/YourService.php` inside the container. Replace the example path with the actual changed file. Do not run a whole-repository formatting fix that modifies unrelated files. If the whole-repository check fails on existing files, report that separately and verify your changed files.

For documentation-only changes, check paths, commands, consistency with source code, Markdown structure and `git diff --check`. PHPUnit and Pint are not needed when no PHP or application configuration changed. Do not execute mutating setup/import commands merely to validate their spelling in documentation.

If Docker is stopped or a dependency is missing, report which command could not run and why. Do not claim verification from reading the code alone. Starting the stack follows the README, but be aware that the app entrypoint automatically runs migrations; inspect setup before starting it against existing data.

## 10. Documentation and completion checklist

Update only the documents affected by the task:

| Change | Documentation to review/update |
|---|---|
| Public behavior, routes, configuration or setup | `README.md` and every translated README (`README.<locale>.md`), with equivalent information; English is the source |
| Material behavior change or delivery milestone | `CHANGELOG.md`, following its existing structure |
| Implemented planned work or newly discovered project gap | Relevant item in `ROADMAP.md` |
| Shared contributor/agent rule or a fixed trap listed here | `AGENTS.md` |

Before saying the task is complete:

- [ ] The requested behavior is implemented, not just described in a plan.
- [ ] Changes stay in the correct layers and preserve unrelated user work.
- [ ] Any schema change has a new migration; model attributes and mapping agree.
- [ ] Relevant regression cases were checked; import tests use fake HTTP only.
- [ ] Applicable tests and formatting checks passed, or the exact limitations are stated.
- [ ] No secrets, generated dependencies or unrelated formatting entered the changes.
- [ ] Affected documentation is accurate; all READMEs agree when changed.
- [ ] The final diff and newly created files were reviewed. `git diff` does not show the contents of untracked files; inspect those separately.

Final response: state the concrete result, summarize verification, and disclose any remaining blocker. Distinguish tests that passed, failed and could not run. Do not mark roadmap work complete just because files were written.
