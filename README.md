# Users API — CodeIgniter 4 backend

REST API (JSON) for user management, consumed by a separate React frontend. Built for the Developer Technical Test (`docs/Developer_Technical_Test.md`).

- **Stack:** PHP 8.2+, CodeIgniter 4.7, MySQL 8.x
- **API base URL expected by the frontend:** `http://localhost:8080` (no `/api` prefix)
- **Database schema:** migrations in `app/Database/Migrations/`; design notes in `docs/database-design.md`

## Requirements

- PHP 8.2+ with `intl`, `mbstring`, `mysqli` (and `json`, enabled by default)
- Composer 2
- MySQL 8.x — local install, or the provided `podman-compose.yml` / Docker Compose

## Run the backend

```bash
# 1. Dependencies
composer install

# 2. Configuration (never commit .env)
cp .env.example .env
#   edit database.default.* / database.tests.* (credentials)
#   and CORS_ALLOWED_ORIGINS (frontend origin, default http://localhost:5173)

# 3. Database — option A: container (creates `ci4` and `ci4_test`, user ci4/ci4)
podman-compose up -d          # or: docker compose -f podman-compose.yml up -d
#   then in .env set username = ci4, password = ci4 for both groups
#   option B: own MySQL — create the `ci4` and `ci4_test` databases (utf8mb4)

# 4. Schema + sample data (20 users)
php spark migrate
php spark db:seed UserSeeder

# 5. Serve
php spark serve               # http://localhost:8080
```

Check: `curl http://localhost:8080/users` returns `200` with `{ "data": [...] }`.

Reset the data: `php spark migrate:refresh && php spark db:seed UserSeeder`.

## Tests

Tests use the `tests` DB group (`database.tests.*` in `.env`); migrations run automatically.

```bash
composer test                                  # whole suite (vendor/bin/phpunit)
vendor/bin/phpunit tests/feature/UsersApiTest.php
vendor/bin/phpunit --filter testMethodName
```

- `tests/feature/UsersApiTest.php` — HTTP tests for every endpoint and status code (200/201/204/400/404/415/422, CORS)
- `tests/database/UserModelTest.php` — model validation and persistence
- `tests/unit/DuplicateKeyTranslatorTest.php` — duplicate-key → 422 mapping

## Frontend

The React app (TypeScript) lives in a separate project. It must call the API at `http://localhost:8080`, and its origin must be listed in `CORS_ALLOWED_ORIGINS`. Run instructions: see the frontend README.

## API

JSON REST API for the `users` resource.

- **Base URL:** `http://localhost:8080` (`php spark serve`). No `/api` prefix.
- **Requirements:** PHP 8.2+ (CodeIgniter 4.7), MySQL 8.x.
- Bodies (`POST`, `PUT`) must be JSON with `Content-Type: application/json`.

### Endpoints

| Method   | Path          | Success                  | Errors          |
| -------- | ------------- | ------------------------ | --------------- |
| `GET`    | `/users`      | `200` `{ "data": [User] }` | `500`         |
| `GET`    | `/users/{id}` | `200` `{ "data": User }` | `404`, `500`    |
| `POST`   | `/users`      | `201` `{ "data": User }` | `400`, `415`, `422`, `500` |
| `PUT`    | `/users/{id}` | `200` `{ "data": User }` | `400`, `404`, `415`, `422`, `500` |
| `DELETE` | `/users/{id}` | `204` (empty body)       | `404`, `500`    |
| `OPTIONS`| `/users`, `/users/{id}` | `204` (CORS preflight) | — |

- `{id}` must be digits; anything else (`/users/abc`, `/users/-1`) is `404 Not found`.
- `PUT` is a **full replacement**: same required fields as `POST`; an omitted `telephone` is cleared (`null`).
- `id`, `created_at`, `updated_at` and unknown fields in the body are ignored. The ID always comes from the URL.

### User resource

| Field        | Type            | Rules                                            |
| ------------ | --------------- | ------------------------------------------------ |
| `id`         | int             | read-only                                        |
| `first_name` | string          | required, max 100                                |
| `last_name`  | string          | required, max 100                                |
| `email`      | string          | required, valid email, max 254, unique; stored trimmed + lowercased |
| `gender`     | string          | required, one of `male`, `female`, `other`       |
| `telephone`  | string \| null  | optional, max 20, digits, spaces and `+-()`      |
| `age`        | int             | required, 1–120                                  |
| `created_at` | string          | read-only, `YYYY-MM-DD HH:MM:SS`                 |
| `updated_at` | string          | read-only, `YYYY-MM-DD HH:MM:SS`                 |

### Examples

Create a user:

```bash
curl -i -X POST http://localhost:8080/users \
  -H 'Content-Type: application/json' \
  -d '{"first_name":"Ana","last_name":"García","email":"ana@example.com","gender":"female","telephone":"+34 600 000 000","age":34}'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json; charset=UTF-8

{
  "data": {
    "id": 1,
    "first_name": "Ana",
    "last_name": "García",
    "email": "ana@example.com",
    "gender": "female",
    "telephone": "+34 600 000 000",
    "age": 34,
    "created_at": "2026-11-12 10:00:00",
    "updated_at": "2026-11-12 10:00:00"
  }
}
```

Other calls:

```bash
curl http://localhost:8080/users                 # 200 { "data": [...] }
curl http://localhost:8080/users/1               # 200 { "data": {...} }
curl -X PUT http://localhost:8080/users/1 \
  -H 'Content-Type: application/json' \
  -d '{"first_name":"Ana","last_name":"López","email":"ana@example.com","gender":"female","age":35}'
curl -i -X DELETE http://localhost:8080/users/1  # 204, empty body
```

### Error contract

Every error response has the same shape. `status` equals the HTTP status code and `errors` is always a JSON object (`{}` when there are no field errors, never `[]`):

```json
{
  "status": 422,
  "message": "Validation failed",
  "errors": { "email": "El email ya está registrado." }
}
```

Field messages come from the model's validation rules (the custom ones are in Spanish).

| Code  | `message`                | When                                                                 |
| ----- | ------------------------ | -------------------------------------------------------------------- |
| `400` | `Malformed JSON body`    | Invalid JSON, empty body, or JSON that is not an object (e.g. `[]`)  |
| `404` | `User not found`         | The ID does not exist (including `/users/0`)                         |
| `404` | `Not found`              | No matching route under `/users` (e.g. `/users/abc`, `/users/1/foo`) |
| `415` | `Unsupported media type` | `POST`/`PUT` without `Content-Type: application/json`                |
| `422` | `Validation failed`      | Field validation errors (`errors.<field>`), including duplicate email |
| `500` | `Internal server error`  | Any unexpected error. No details are returned in any environment; they are logged in `writable/logs/` |

### CORS

CORS uses CodeIgniter's built-in `cors` filter on the `/users` routes:

- Allowed origins: `CORS_ALLOWED_ORIGINS` (comma-separated, exact match). If it is empty or unset, no origin is allowed. `*` is ignored.
- Methods: `GET, POST, PUT, DELETE, OPTIONS`. Headers: `Content-Type, Authorization, If-Match`.
- Credentials are not supported (`Access-Control-Allow-Credentials` is never sent).

### `.env` variables

Copy `.env.example` to `.env` and adjust:

| Variable                     | Example                  | Purpose                                      |
| ---------------------------- | ------------------------ | -------------------------------------------- |
| `CI_ENVIRONMENT`             | `development`            | Environment                                  |
| `app.baseURL`                | `'http://localhost:8080/'` | API base URL                               |
| `database.default.*`         | see `.env.example`       | Main DB (`hostname`, `database`, `username`, `password`, `DBDriver = MySQLi`, `port`, …) |
| `database.tests.*`           | see `.env.example`       | DB used by `composer test`                   |
| `CORS_ALLOWED_ORIGINS`       | `http://localhost:5173`  | Frontend origin(s), e.g. `http://localhost:5173,https://app.example.com` |

Keep `DBDebug = true` (the default in `app/Config/Database.php`). The API relies on database exceptions to turn duplicate-email races into `422`.
