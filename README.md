# NUERS

National Unified Electronic Receipt System, version 1.0.0.

NUERS uses Laravel 12 for authentication, authorization, transaction processing,
document storage, reporting, and operational jobs. React 19 and TypeScript provide
the public website, merchant workspace, and administration interface. A separate
server renderer produces receipt and report HTML for Chrome PDF generation.

## Local development

Use PHP 8.2 or newer with the extensions required by `composer.lock`, Composer 2,
and a Node.js version supported by `package-lock.json` and Vite 7. Use the deployed
PHP version when validating a release. SQLite is suitable for isolated functional
tests; database-specific behavior must also be verified against the deployment's
MariaDB/MySQL version.

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build:all
php artisan serve
```

Run `npm run dev` in a separate terminal for frontend development. The default
seeder intentionally creates no accounts or financial data. Provision accounts
through the authorized registration workflow. `scripts/prepare-vapt-lab.php`
supports a separate, synthetic test environment; inspect its safeguards and
arguments before using it. Never point test preparation at the production database.

## Source map

| Directory | Purpose |
| --- | --- |
| `app/Http` | Request validation, controllers, middleware, and authorization entry points |
| `app/Services` | Business rules, tenant scoping, documents, reports, integrations, and operations |
| `app/Console` | Scheduled and operator commands |
| `routes` | HTTP and console route definitions |
| `database/migrations` | Schema changes and indexes |
| `src/pages` | Public, merchant, and administrator pages |
| `src/components` | Shared interface and document components |
| `src/lib` | Client transport, formatting, and document contracts |
| `scripts` | Build tools, reference-data generation, and isolated test preparation |
| `tests` | PHP feature/unit tests and JavaScript contract tests |
| `deployment` | Deployment guidance, recovery/security operations, and historical technical evidence |
| `public` | Application assets and published documentation |

`src/lib/supabase.ts` is the existing compatibility client for the Laravel API.
Its filename does not indicate that the production application uses hosted Supabase.
The retired Supabase prototype is retained in private recovery storage outside
the review source. Laravel's `database/migrations` is the current schema migration path.

## Verification

```sh
npm run typecheck
npm run build:all
npm test
npm run format:check
php vendor/bin/pint --test app bootstrap/app.php bootstrap/providers.php config database routes tests
php vendor/bin/phpunit
```

PDF tests require an installed Chrome/Chromium executable. Set
`NUERS_PDF_CHROME` when it cannot be discovered automatically. The PHP suite uses
an isolated SQLite database and fake mail settings. It does not establish live
BIR acceptance, production load capacity, or offsite recovery readiness.

Use `npm run format` for TypeScript/React/CSS and Laravel Pint for PHP. Formatting
rules are recorded in `.editorconfig`, `.prettierrc.json`, and `pint.json`.

## Release and review boundaries

Install dependencies from the lockfiles. `vendor`, `node_modules`, `dist`,
`public/build`, and `bootstrap/ssr` are generated and excluded from Git. The
production PDF renderer still needs its Node dependencies at runtime; do not
remove them from a deployed installation merely because they are excluded from
source control.

`npm run build:all` builds Laravel assets, the document renderer, and the static
frontend. Deploy assets and their entry manifests together. Preserve writable
storage, uploaded merchant files, environment configuration, and the established
server runtime. Back up before deployment and verify application health afterward.

Review packages must exclude `.env`, `.local`, credentials, database files,
merchant uploads, runtime logs, recovery backups, `.git`, and generated dependency
trees. Keep operational evidence and backups in their private storage locations.
Removing tracked generated files does not remove them from earlier Git history;
review or sanitize historical exports separately before sharing a repository.

Create a review archive with `python3 scripts/package-source-review.py --output
output/NUERS-source-review.zip`. The package includes a SHA-256 manifest and omits
private environment state, historical audit collections, and dependency trees.

Changes to authorization, invoice numbering, accounting, data retention, or BIR
integration require focused tests and domain review. Retain truthful provenance,
dependency licenses, and audit history when preparing material for reviewers.
