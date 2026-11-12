# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project context

CodeIgniter 4 (`^4.7`, PHP 8.2+) appstarter, currently unmodified skeleton. Purpose: backend for a developer technical test — spec in `docs/Developer_Technical_Test.md` (read it before implementing anything). Summary of requirements:

- `users` table: `id` (PK, auto-inc), `first_name`*, `last_name`*, `email`* (valid, unique), `gender`*, `telephone` (optional), `age`* (* = required).
- Persistence via Model + Query Builder + migrations (no in-memory arrays). Config via `.env` only — no credentials in code.
- Server-side validation (required, email format, unique email).
- JSON REST API with CORS enabled for a separate React (Vite/Next, TypeScript) frontend:
  - `GET /users` → 200; `GET /users/{id}` → 200/404
  - `POST /users` → 201/422; `PUT /users/{id}` → 200/422/404; `DELETE /users/{id}` → 204/404
- Seeder with ≥10 sample users.
- ≥1 meaningful automated test (e.g. API/feature test on a USER endpoint).
- Deliverables: README explaining how to run backend + frontend, `.env.example`, migrations (or SQL dump), API base URL the frontend expects. Never commit `vendor/`, `node_modules/`, or `.env`.

Note: this directory is not its own git repo — the enclosing git root is the user's home dir. Don't commit from here unless the user sets up a repo.

## Commands

```bash
composer install
cp env .env                         # then set CI_ENVIRONMENT, app.baseURL, database.default.*
php spark serve                     # dev server at http://localhost:8080
php spark routes                    # list registered routes
php spark make:migration CreateUsers   # also make:model, make:controller, make:seeder
php spark migrate                   # migrate:rollback, migrate:refresh
php spark db:seed UserSeeder
composer test                       # = vendor/bin/phpunit (config: phpunit.dist.xml, suite ./tests)
vendor/bin/phpunit tests/unit/HealthTest.php          # single file
vendor/bin/phpunit --filter testMethodName            # single test
```

Tests needing a DB use the `tests` connection group — set `database.tests.*` in `.env` (template in `env`) or uncomment the `<env>` block in `phpunit.dist.xml`. Use `DatabaseTestTrait` (`$migrate`, `$seed`) and `FeatureTestTrait` for HTTP endpoint tests; examples in `tests/database/` and `tests/_support/`.

## Architecture (CI4)

- Web root is `public/` (`public/index.php`); never point server at project root.
- Namespaces: `App\` → `app/`, `Config\` → `app/Config/`, `Tests\Support\` → `tests/_support/`.
- Routes: `app/Config/Routes.php` (use `$routes->resource('users', ...)` or explicit verbs; keep autoRoute off in `app/Config/Routing.php`).
- Controllers extend `BaseController`; for the REST API prefer `CodeIgniter\RESTful\ResourceController` / `ResponseTrait` (`respond`, `respondCreated`, `respondDeleted`, `failNotFound`, `failValidationErrors` → 422).
- Validation rules can live on the Model (`$validationRules`, `is_unique[users.email,id,{id}]` for updates) or `app/Config/Validation.php`.
- CORS: configure `app/Config/Cors.php` and apply the `cors` filter in `app/Config/Filters.php` (needs OPTIONS preflight routes handled).
- Migrations: `app/Database/Migrations/`; seeders: `app/Database/Seeds/` (`fakerphp/faker` available in dev).
- Runtime logs/cache/session: `writable/`.
