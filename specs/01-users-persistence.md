# SPEC 01 — Tabla users, UserModel y UserSeeder

> **Status:** Implemented · **Depends on:** — · **Date:** 2026-11-12
> **Objective:** Crear la persistencia de usuarios (migración `users`, `UserModel` con validación y normalización de email, `UserSeeder` con 20 usuarios reproducibles) cubierta por tests de modelo, según `docs/database-design.md`.

## Scope

**In:**

- Migración `app/Database/Migrations/<timestamp>_CreateUsersTable.php`: tabla `users` según DDL de `docs/database-design.md` §3, incluyendo `CHECK (age BETWEEN 1 AND 120)` vía `ALTER TABLE` tras `createTable()`, y `down()` con `dropTable`.
- `app/Models/UserModel.php`: config (`$table`, `$allowedFields`, `$returnType = 'array'`, `$useTimestamps = true`), `$validationRules` del diseño §6 y mensajes de validación.
- Normalización de email (`trim` + minúsculas) en `UserModel`, ejecutada **antes** de validar y persistir, sobrescribiendo `insert()` y `update()`.
- Inyección de `id` en los datos de `update()` para que `is_unique[users.email,id,{id}]` excluya el propio registro.
- `app/Database/Seeds/UserSeeder.php`: 20 usuarios con Faker `es_ES`, semilla fija, emails únicos, ~30 % `telephone` nulo, `age` 18–80, `gender` del ENUM.
- `tests/database/UserModelTest.php`: inserción válida, email duplicado, requeridos faltantes, `telephone` NULL, rango de `age`, normalización de email.
- `.env.example` con claves `CI_ENVIRONMENT`, `app.baseURL`, `database.default.*` y `database.tests.*` (sin credenciales reales).

**Out of scope (for future specs):**

- Controladores y rutas REST (`/users`).
- CORS.
- Manejo HTTP de errores (404/422) y conversión del error MySQL 1062 a 422.
- Control de concurrencia (`If-Match`, columna `version`).
- Índices adicionales para alto volumen (`idx_users_last_first`, etc.).
- Normalización en `insertBatch()` / `updateBatch()` (no usados por la API).
- Cambios en `podman-compose.yml` o `docker/mysql/init/` (ya crean `ci4` y `ci4_test`).
- README.

## Data model

### Tabla `users`

Fuente de verdad: DDL en `docs/database-design.md` §3. Resumen:

| Columna      | Tipo                            | Nulo | Restricción                |
| ------------ | ------------------------------- | ---- | -------------------------- |
| `id`         | `INT UNSIGNED AUTO_INCREMENT`   | No   | PK                         |
| `first_name` | `VARCHAR(100)`                  | No   | —                          |
| `last_name`  | `VARCHAR(100)`                  | No   | —                          |
| `email`      | `VARCHAR(254)`                  | No   | `UNIQUE uk_users_email`    |
| `gender`     | `ENUM('male','female','other')` | No   | —                          |
| `telephone`  | `VARCHAR(20)`                   | Sí   | default `NULL`             |
| `age`        | `TINYINT UNSIGNED`              | No   | `CHECK ck_users_age` 1–120 |
| `created_at` | `DATETIME`                      | Sí   | —                          |
| `updated_at` | `DATETIME`                      | Sí   | —                          |

Tabla: `ENGINE=InnoDB`, `CHARSET=utf8mb4`, `COLLATE=utf8mb4_unicode_ci`.

### `UserModel`

```php
protected $table         = 'users';
protected $primaryKey    = 'id';
protected $returnType    = 'array';
protected $useTimestamps = true;
protected $allowedFields = ['first_name', 'last_name', 'email', 'gender', 'telephone', 'age'];

protected $validationRules = [
    'id'         => 'permit_empty|is_natural_no_zero',
    'first_name' => 'required|string|max_length[100]',
    'last_name'  => 'required|string|max_length[100]',
    'email'      => 'required|valid_email|max_length[254]|is_unique[users.email,id,{id}]',
    'gender'     => 'required|in_list[male,female,other]',
    'telephone'  => 'permit_empty|max_length[20]|regex_match[/^[0-9+\-() ]+$/]',
    'age'        => 'required|is_natural_no_zero|less_than_equal_to[120]',
];
```

`$validationMessages`: mensajes en español para `email.is_unique`, `email.valid_email`, `gender.in_list`, `age.less_than_equal_to`, `age.is_natural_no_zero`. El resto usa los mensajes por defecto de CI4.

Comportamiento añadido:

- `insert($row)` / `update($id, $row)`: si `$row` trae `email` string → `strtolower(trim($email))` antes de `parent::`.
- `update($id, $row)`: si `$id` es escalar y `$row` es array → `$row['id'] = $id` antes de `parent::update()`.
- Normalización en un método privado único (`normalizeEmail(array $row): array`) — sin duplicar lógica.

### Seeder

- Faker `es_ES`, `$faker->seed(20261115)` (20261112 daba 14/20 `telephone` NULL, fuera del criterio 3–9).
- 20 filas: `firstName`, `lastName`, `unique()->safeEmail()` normalizado, `gender` ∈ ENUM, `age` 18–80, `telephone` = `null` si `$faker->boolean(30)`, si no `$faker->numerify('+34 6## ### ###')` (≤ 20 chars, cumple el regex).
- Inserta vía `UserModel::insert()` por fila → pasa validación, normalización y timestamps.

## Implementation plan

1. **Configuración.** Crear `.env.example` desde `env`: `CI_ENVIRONMENT = development`, `app.baseURL = 'http://localhost:8080/'`, `database.default.*` (`ci4`) y `database.tests.*` (`ci4_test`) con `DBDriver = MySQLi`, `charset = utf8mb4`, `DBCollat = utf8mb4_unicode_ci`, valores placeholder (`changeme`). Copiar a `.env` local (no versionado). Verificación: `php spark migrate:status` conecta sin error.
2. **Migración.** `php spark make:migration CreateUsersTable`; implementar `up()` según §Data model + `ALTER TABLE users ADD CONSTRAINT ck_users_age CHECK (age BETWEEN 1 AND 120)`; `down()` con `dropTable('users', true)`. Verificación: `php spark migrate` → `SHOW CREATE TABLE users` muestra PK, `uk_users_email`, `ck_users_age`, collation `utf8mb4_unicode_ci`; `php spark migrate:rollback` elimina la tabla; `migrate` de nuevo la recrea.
3. **UserModel base.** Crear `app/Models/UserModel.php` con config, `$validationRules` y `$validationMessages` (sin overrides). Crear `tests/database/UserModelTest.php` (`DatabaseTestTrait`, `$migrate = true`, `$refresh = true`, `$namespace = 'App'`) con tests: inserción válida, email duplicado rechazado, campo requerido faltante rechazado, `telephone` NULL aceptado, `age` 0 y 121 rechazados, `age` 1 y 120 aceptados. Verificación: `composer test` en verde.
4. **Normalización + `id` en update.** Añadir `normalizeEmail()` privado y overrides de `insert()` y `update()`. Añadir tests: `'  Foo@Example.COM '` se guarda como `foo@example.com`; email que solo difiere en mayúsculas/espacios de uno existente se rechaza; `update($id, ['email' => <su propio email>])` devuelve `true`. Verificación: `composer test` en verde.
5. **Seeder.** Crear `app/Database/Seeds/UserSeeder.php` según §Data model. Verificación: `php spark migrate:refresh` + `php spark db:seed UserSeeder` → `SELECT COUNT(*) FROM users` = 20; repetir refresh + seed produce los mismos emails.

## Acceptance criteria

- [ ] `.env.example` existe, contiene `database.default.*` y `database.tests.*`, y no tiene credenciales reales.
- [ ] `.env` no está versionado (cubierto por `.gitignore`).
- [ ] `php spark migrate` crea `users` sin errores.
- [ ] `SHOW CREATE TABLE users` muestra: PK `id` `INT UNSIGNED AUTO_INCREMENT`, `UNIQUE KEY uk_users_email`, `CHECK ck_users_age` (`age` 1–120), `ENUM('male','female','other')`, `telephone` nullable, collation `utf8mb4_unicode_ci`.
- [ ] `INSERT` SQL directo con `age = 0` o `age = 121` falla por `ck_users_age`.
- [ ] `php spark migrate:rollback` elimina `users`; `php spark migrate` la recrea.
- [ ] `UserModel::insert()` con datos válidos devuelve un `id` > 0 y rellena `created_at`/`updated_at`.
- [ ] `UserModel::insert()` con email ya existente devuelve `false` y `errors()` tiene clave `email`.
- [ ] `UserModel::insert()` sin `first_name`, `last_name`, `email`, `gender` o `age` devuelve `false` con error en ese campo.
- [ ] `UserModel::insert()` sin `telephone` (o `null`) tiene éxito y la fila guarda `NULL`.
- [ ] `UserModel::insert()` con `age` 0 o 121 devuelve `false`; con `age` 1 o 120 tiene éxito.
- [ ] `UserModel::insert()` con email `'  Foo@Example.COM '` guarda `foo@example.com`.
- [ ] `UserModel::insert()` con email que solo difiere de uno existente en mayúsculas/espacios devuelve `false`.
- [ ] `UserModel::update($id, ['email' => <su propio email>])` devuelve `true`.
- [ ] `php spark db:seed UserSeeder` sobre tabla vacía deja exactamente 20 filas.
- [ ] Entre 3 y 9 de esas 20 filas tienen `telephone` `NULL` (~30 %).
- [ ] Dos ejecuciones `migrate:refresh` + `db:seed UserSeeder` producen la misma lista de emails.
- [ ] `composer test` pasa en verde (incluye `tests/database/UserModelTest.php`).
- [ ] No hay credenciales en código PHP (solo en `.env`).

## Decisions

- **Yes:** `CHECK ck_users_age` vía `ALTER TABLE` raw en la migración. Forge no soporta `CHECK`; queremos doble barrera (BD + Model). MySQL 8.4 lo aplica.
- **No:** solo validación en Model para `age`. Pierde integridad ante escrituras fuera de la app.
- **Yes:** normalizar email sobrescribiendo `insert()`/`update()` en `UserModel`. `BaseModel` valida antes de `beforeInsert`/`beforeUpdate` (`BaseModel.php:868,1046`), así que los callbacks normalizarían tarde.
- **No:** callbacks `beforeInsert`/`beforeUpdate` para normalizar. Corren después de validar.
- **No:** normalizar en controladores. Duplica responsabilidad; el modelo es dueño del dato.
- **Yes:** inyectar `id` en `$row` en `update()`. `BaseModel::update()` no lo hace y `{id}` de `is_unique` quedaría sin llenar. `id` no está en `$allowedFields`, se descarta antes de persistir.
- **No:** normalizar en `insertBatch()`/`updateBatch()`. La API no los usa; fuera de scope.
- **Yes:** `$returnType = 'array'`. Simple para respuestas JSON.
- **No:** Entity. Sin lógica de dominio que lo justifique.
- **Yes:** Faker `es_ES` con semilla fija `20261115`, 20 usuarios. Datos reproducibles para tests y demos.
- **No:** Faker aleatorio sin semilla. Datos cambian en cada seed.
- **No:** usuarios hardcodeados. Más mantenimiento sin ventaja.
- **Yes:** seeder con `UserModel::insert()` por fila. Pasa validación, normalización y timestamps; 20 filas no justifican `insertBatch()`.
- **Yes:** collation `utf8mb4_unicode_ci` en tabla y en `.env.example`. Manda `docs/database-design.md`; Docker usa `general_ci` a nivel servidor pero la tabla la fija explícitamente.
- **Yes:** PHP 8.2+. Lo exigen `composer.json` (`^8.2`) y CI 4.7; el 8.1+ pedido no es alcanzable.
- **Yes:** tests a nivel Model (`tests/database/UserModelTest.php`) con `$namespace = 'App'`. Validan migración y reglas sin depender de controladores; tests de endpoint van al spec de API.
- **No:** modificar `podman-compose.yml` ni `docker/mysql/init/`. Ya crean `ci4` y `ci4_test` con grants.
- **No:** soft delete. Bloquearía reutilizar emails; `DELETE` debe dejar 404 (ver diseño §2.4).

## Risks

| Risk                                                                                  | Mitigation                                                                                                                     |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Carrera: dos inserts simultáneos con mismo email pasan `is_unique`                    | `uk_users_email` en BD lo bloquea. Conversión de MySQL 1062 a 422 va en el spec de API.                                        |
| `CHECK` ignorado en MySQL < 8.0.16                                                    | Docker fija MySQL 8.4. La validación del Model sigue siendo la barrera principal.                                              |
| Upgrade de CI4 cambia el orden validate/callbacks o la firma de `insert()`/`update()` | Overrides mínimos que delegan a `parent::`. Los tests de normalización y de `update()` con email propio detectan la regresión. |
| Upgrade de Faker cambia la secuencia para la misma semilla                            | Los tests no dependen de datos del seeder. Solo cambiaría la lista de emails de la demo.                                       |
| `unique()` de Faker se agota                                                          | 20 emails sobre el pool de `safeEmail()`: riesgo despreciable.                                                                 |
| BD `ci4_test` no existe si el volumen Docker ya existía antes del script init         | Documentado: `podman-compose down -v` y levantar de nuevo, o crearla a mano.                                                   |

## What is **not** in this spec

- Controladores y rutas REST (`/users`).
- CORS.
- Manejo HTTP de errores y conversión de MySQL 1062 a 422.
- Control de concurrencia.
- Índices para alto volumen.
- Normalización en `insertBatch()`/`updateBatch()`.
- Cambios en Docker.
- README.

Cada uno, si llega, va en su propio spec.
