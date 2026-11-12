# SPEC 03 — Control de concurrencia optimista en PUT /users/{id}

> **Status:** Implemented · **Depends on:** SPEC 01, SPEC 02 · **Date:** 2026-11-12
> **Objective:** Evitar que un `PUT /users/{id}` sobrescriba en silencio un cambio guardado por otro cliente después de que el primero cargó el usuario (escenario Q3 de la prueba), con versión por fila, `ETag` / `If-Match` y respuestas 412/428.

## Scope

**In:**

- Migración `app/Database/Migrations/2026-11-13-090000_AddVersionToUsers.php`: columna `version INT UNSIGNED NOT NULL DEFAULT 1` después de `age`; las filas existentes quedan en 1. `down()` borra la columna.
- `UserModel`:
  - `version` entra en `$allowedFields`, con la regla `permit_empty|is_natural_no_zero`.
  - Nuevo método `updateIfVersion($id, array $row, int $expectedVersion): false|int`.
- `Users` controller:
  - `show`, `create` y `update` responden con `ETag: "<version>"`.
  - `update` exige `If-Match`: 428 si falta, 412 si la versión no coincide.
  - `version` del body se descarta en `create` y `update`.
- `Config\Cors`: `exposedHeaders = ['ETag']`.
- `tests/feature/UsersApiTest.php`:
  - Helper `putUser()` que envía `If-Match` (por defecto `"1"`); los tests de `PUT` existentes lo usan.
  - Tests nuevos de concurrencia (ver §Acceptance criteria).
- Docs: sección "Concurrent edits" y códigos 412/428 en el README, `version` en `docs/database-design.md` §2 y §8, enmiendas en SPEC 01 y SPEC 02.
- Frontend (`front-test`, otro repo):
  - Envía `If-Match` con la versión cargada.
  - Ante un 412 recarga el usuario, cierra el formulario y avisa con un toast.

**Out of scope (for future specs):**

- `If-Match` en `DELETE`.
- `If-None-Match` / `304 Not Modified` en `GET`.
- `If-Match: *` y ETags débiles (`W/"…"`): se rechazan con 412.
- `ETag` por fila en `GET /users`: la versión ya viaja en el body.
- Merge automático de cambios en el cliente.
- Concurrencia en `POST`: la UNIQUE de email ya cubre la creación duplicada.

## Data model

### Tabla `users` (cambio)

| Columna   | Tipo           | Nulo | Default | Uso                                               |
| --------- | -------------- | ---- | ------- | ------------------------------------------------- |
| `version` | `INT UNSIGNED` | No   | `1`     | +1 en cada `PUT` correcto; se expone como `ETag` |

### Recurso `User` (JSON)

Igual que en SPEC 02, más `"version": 1` (int, solo lectura), entre `age` y `created_at`.

### `UserModel` (añadido)

```php
protected $allowedFields = ['first_name', 'last_name', 'email', 'gender', 'telephone', 'age', 'version'];
// $validationRules: 'version' => 'permit_empty|is_natural_no_zero'

/**
 * UPDATE ... SET ..., version = $expectedVersion + 1
 * WHERE id = ? AND version = $expectedVersion
 *
 * @return false|int false = validation failed (errors()); 1 = updated; 0 = version mismatch
 */
public function updateIfVersion($id, array $row, int $expectedVersion): false|int;
```

- Reutiliza `update()`: conserva la normalización de email, la inyección de `id` para `is_unique` y los timestamps.
- Si la validación falla o se lanza una excepción, llama a `builder()->resetQuery()` para que el `WHERE version` pendiente no contamine la siguiente consulta.
- `affectedRows()` es fiable: en una coincidencia `version` siempre cambia, así que 0 filas solo puede significar que la versión no coincide.

### Contrato HTTP

| Situación                                         | Respuesta                                                            |
| ------------------------------------------------- | -------------------------------------------------------------------- |
| `GET /users/{id}`, `POST`, `PUT` correctos         | Cabecera `ETag: "<version>"`                                         |
| `PUT` sin `If-Match`                              | `428 Precondition required`, `errors.If-Match`                       |
| `If-Match` ≠ versión actual, mal formado, `*` o débil | `412 Precondition failed`, `errors.version`, `ETag` = versión actual |
| `If-Match` = versión actual, body válido          | `200`, `version + 1`, nuevo `ETag`                                   |
| `If-Match` = versión actual, body inválido        | `422` (la versión no cambia)                                         |

Orden de evaluación en `update`: 404 → 428 → 415/400 (body) → 412 (comparación en PHP, antes de validar) → 422 → `UPDATE` atómico (0 filas → 412).

## Implementation plan

1. **Migración.** Crear `AddVersionToUsers` con `addColumn` (`after => 'age'`) y `down()` con `dropColumn`. Verificación: `php spark migrate` → `SHOW CREATE TABLE users` muestra `version int unsigned NOT NULL DEFAULT '1'`; `migrate:rollback` la elimina.
2. **Modelo.** Añadir `version` a `$allowedFields` y a `$validationRules`. Implementar `updateIfVersion()`. Verificación: test `updateIfVersion` → 1 y luego 0 con la misma versión.
3. **Controlador.**
   - `readUserBody()`: `readJsonBody()` sin `id` ni `version`; lo usan `create` y `update`.
   - `readIfMatchVersion()`: 428 si falta la cabecera; acepta solo `"<1–10 dígitos>"`; cualquier otro valor → -1 (nunca coincide).
   - `respondUser()`: envelope + `ETag`.
   - `respondVersionConflict()`: 412 + `ETag` actual.
   - `update()` según el orden del contrato.
4. **CORS.** `exposedHeaders = ['ETag']` en `Config\Cors`.
5. **Tests.** Helper `putUser()`; adaptar los `PUT` existentes; añadir los casos de §Acceptance criteria; actualizar el test de campos de `show` (10 claves). Verificación: `composer test` en verde.
6. **Docs.** README (Endpoints, User resource, ejemplos, Error contract, Concurrent edits, CORS, Tests), `docs/database-design.md`, enmiendas en SPEC 01 y SPEC 02.
7. **Frontend.**
   - `updateUser(id, input, version)` envía `If-Match`.
   - `useUsers` y `useUser` recargan el usuario ante un 412.
   - Las páginas cierran el formulario y muestran un toast.
   - Verificación: `npm run build` y `npm run lint` en verde.

## Acceptance criteria

**Esquema y modelo**

- [ ] `php spark migrate` añade `version` (`INT UNSIGNED NOT NULL DEFAULT 1`); las filas existentes quedan en 1; `migrate:rollback` la quita.
- [ ] `updateIfVersion($id, $row, 1)` sobre un usuario en versión 1 → `1` y la fila queda en versión 2; repetir con `1` → `0` sin cambios.
- [ ] Una validación fallida en `updateIfVersion()` → `false`, la versión no cambia y la siguiente consulta del modelo no arrastra `WHERE version`.

**HTTP**

- [ ] `POST /users` → 201 con `ETag: "1"` y `data.version = 1`, aunque el body traiga `version`.
- [ ] `GET /users/{id}` → `ETag` igual a `data.version`.
- [ ] `PUT` con `If-Match` correcto → 200, `data.version` +1 y nuevo `ETag`; `version` del body ignorada.
- [ ] Escenario Q3: dos `PUT` con `If-Match: "1"` → el primero 200, el segundo 412 con `errors.version` y `ETag: "2"`; la BD conserva el cambio del primero.
- [ ] `PUT` con versión vieja y body inválido → 412 (no 422).
- [ ] `PUT` sin `If-Match` → 428 con `errors.If-Match`; sin cambios en BD.
- [ ] `If-Match` `1` (sin comillas), `W/"1"`, `*`, `"abc"` o una versión futura → 412; sin cambios en BD.
- [ ] `PUT` a un ID inexistente → 404 (antes que 428).
- [ ] Los casos 400/415/422 de `PUT` de SPEC 02 siguen pasando enviando `If-Match`.

**CORS**

- [ ] Las respuestas a un origen permitido incluyen `Access-Control-Expose-Headers: ETag`.
- [ ] El preflight sigue permitiendo `If-Match`.

**Calidad**

- [ ] `composer test` en verde.
- [ ] `Users.php` sigue sin SQL ni Query Builder: el `WHERE version` vive en `UserModel`.
- [ ] README y specs coherentes con el código.

## Decisions

- **Yes:** columna `version` (contador entero). Exacta y barata.
- **No:** `updated_at` como versión. `DATETIME` tiene resolución de segundo: dos guardados en el mismo segundo serían indistinguibles.
- **No:** hash del contenido como ETag. Hay que recalcularlo en cada lectura y no aporta nada frente a un contador.
- **Yes:** la comparación va en el propio `UPDATE` (`WHERE id = ? AND version = ?`). Cierra la carrera entre leer y escribir sin bloqueos de BD.
- **Yes:** además, una comparación previa en PHP. Permite responder 412 antes de validar datos construidos sobre una copia vieja; la carrera posterior la cierra el `WHERE`.
- **No:** `SELECT ... FOR UPDATE` (bloqueo pesimista). Mantiene un bloqueo durante la petición y no detecta cambios hechos entre el `GET` del cliente y su `PUT`.
- **Yes:** 412 para versión vieja y 428 sin `If-Match`. Es la semántica HTTP de las precondiciones (RFC 9110 / RFC 6585); el 428 obliga a los clientes a enviar la versión en vez de omitirla por descuido.
- **No:** 409 Conflict. Es válido, pero aquí el conflicto nace de una precondición HTTP.
- **No:** aceptar `If-Match: *`. Equivaldría a desactivar la protección.
- **Yes:** ETag = `"<version>"` y `version` también en el body. El listado no tiene ETag por fila, así que el cliente necesita la versión en los datos.
- **Yes:** `version` en `$allowedFields`, quitada del body en el controlador. `protectFields` la descartaría si no estuviera permitida; quitarla del body evita que el cliente la fije.
- **No:** `If-Match` en `DELETE` por ahora. Borrar no pierde cambios de otro agente; se puede añadir en otro spec.
- **Yes:** en el frontend, ante un 412 se recarga el usuario y se cierra el formulario. Reenviar el formulario con datos viejos volvería a pisar el cambio del otro agente.

## Risks

| Risk                                                                    | Mitigation                                                                                                   |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Un `WHERE version` pendiente contamina la siguiente consulta del modelo | `resetQuery()` cuando falla la validación o hay excepción; test `testValidationErrorDoesNotBumpVersion`.     |
| Un upgrade de CI4 cambia cómo `update()` usa el builder                 | Los tests de 412, 0 filas y `version` +1 detectan la regresión.                                              |
| Clientes antiguos sin `If-Match` dejan de poder editar (428)            | Cambio deliberado; documentado en README y en el contrato de error. El frontend del repo ya envía la cabecera. |
| El navegador no deja leer `ETag` en peticiones cross-origin             | `exposedHeaders = ['ETag']` + test; además `version` viaja en el body.                                       |
| El contador `version` se desborda                                       | `INT UNSIGNED` admite ~4.290 millones de ediciones por fila: no alcanzable en este dominio.                 |
| Escrituras fuera de la API no suben `version`                           | Toda escritura pasa por `UserModel`; un SQL manual que no la suba sería un error de proceso, no de la API.   |

## What is **not** in this spec

- `If-Match` en `DELETE`.
- `If-None-Match` / 304.
- `If-Match: *` y ETags débiles.
- `ETag` por fila en el listado.
- Merge automático en el cliente.
- Autenticación (quién editó) y auditoría de versiones.

Cada uno, si llega, va en su propio spec.
