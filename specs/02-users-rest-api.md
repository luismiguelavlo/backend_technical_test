# SPEC 02 — API REST JSON de /users con CORS

> **Status:** Implemented · **Depends on:** SPEC 01 · **Date:** 2026-11-12
> **Amended by:** [SPEC 03](03-optimistic-concurrency.md) — `version` en el recurso, cabecera `ETag`, `PUT` condicionado por `If-Match` (412/428) y CORS que expone `ETag`.
> **Amended by:** [SPEC 04](04-users-list-query.md) — `GET /users` devuelve una página (`{ data, meta }`) con búsqueda, filtro, orden y cursor; parámetros inválidos → 400. Las secciones afectadas lo indican.
> **Objective:** Exponer el CRUD JSON de `/users` (200/201/204/400/404/415/422/500) sobre `UserModel`, con contrato de respuesta uniforme, CORS configurable por `.env` y tests de integración contra la BD de pruebas.

## Scope

**In:**

- Rutas en `app/Config/Routes.php`: `GET /users`, `GET /users/(:num)`, `POST /users`, `PUT /users/(:num)`, `DELETE /users/(:num)` y `OPTIONS` para preflight de `/users` y `/users/(:num)`. Sin prefijo `/api`. `autoRoute` sigue desactivado.
- `app/Controllers/BaseApiController.php` (extiende `ResourceController`): métodos únicos para respuestas de éxito (envelope `{ "data": ... }`) y de error (`{ status, message, errors }`), lectura del body JSON (415/400) y captura de `DatabaseException` en escrituras.
- `app/Controllers/Users.php` (extiende `BaseApiController`): `index`, `show`, `create`, `update`, `delete`. Sin SQL ni códigos MySQL.
- `app/Database/DuplicateKeyTranslator.php`: traduce MySQL 1062 a campo, identificando la restricción por nombre.
- `UserModel`: añadir el mapa `$uniqueKeys = ['uk_users_email' => 'email']`. Sin otros cambios de comportamiento.
- `app/Exceptions/ApiExceptionHandler.php` + `Config\Exceptions::handler()`: 404/500 JSON para rutas con prefijo en `$apiPrefixes = ['users']`; resto → handler por defecto de CI4.
- CORS nativo CI4: `Config\Cors` lee `CORS_ALLOWED_ORIGINS` (lista por comas), métodos `GET, POST, PUT, DELETE, OPTIONS`, cabeceras `Content-Type, Authorization, If-Match`, `supportsCredentials = false`, sin `*` (SPEC 03 añade `exposedHeaders = ['ETag']`); filtro `cors` aplicado a las rutas de `/users` en `Config\Filters`.
- `.env.example`: añadir `CORS_ALLOWED_ORIGINS = http://localhost:5173`.
- `tests/feature/UsersApiTest.php` (BD `tests`): éxito, 400, 404, 415, 422 (validación y email duplicado), 500 simulado, 204 sin body, CORS (origen permitido/no permitido, preflight) y ruta ajena a la API con handler por defecto.
- `tests/unit/DuplicateKeyTranslatorTest.php`: 1062 con `uk_users_email` → `email`; 1062 con otra clave → `null`; otro código → `null`.
- README: solo sección "API" (base URL `http://localhost:8080`, endpoints, códigos, ejemplos, contrato de error, CORS, variables `.env`, PHP 8.2+).

**Out of scope (for future specs):**

- Autenticación, JWT, sesiones, CSRF.
- Paginación, búsqueda, ordenación (el envelope queda preparado para `meta`). → Implementado en SPEC 04.
- Control de concurrencia (`If-Match`/`ETag`, 409/412); solo se permite la cabecera en CORS. → Implementado en SPEC 03.
- `PATCH` / actualización parcial.
- Capa de servicio (`UserService`).
- `supportsCredentials = true`.
- Rate limiting.
- README completo (instalación y ejecución de backend + frontend).
- Frontend React.
- Cambios en migración, seeder o Docker.

## Data model

Sin cambios en la tabla `users`. Reutiliza migración y `UserModel` de SPEC 01. (SPEC 03 añade después la columna `version`.)

### Recurso `User` (JSON)

```json
{
  "id": 1,
  "first_name": "Ana",
  "last_name": "García",
  "email": "ana@example.com",
  "gender": "female",
  "telephone": null,
  "age": 34,
  "version": 1,
  "created_at": "2026-11-12 10:00:00",
  "updated_at": "2026-11-12 10:00:00"
}
```

Tal como lo devuelve `UserModel` (`$returnType = 'array'`), sin transformación. `version` existe desde SPEC 03.

### Request body (POST / PUT)

```json
{
  "first_name": "Ana",
  "last_name": "García",
  "email": "ana@example.com",
  "gender": "female",
  "telephone": "+34 600 000 000",
  "age": 34
}
```

- `Content-Type: application/json` obligatorio.
- PUT = reemplazo completo: mismos requeridos que POST.
- `id`, `created_at`, `updated_at` y campos desconocidos en el body → descartados (`$allowedFields`; `id` siempre de la URL).
- *SPEC 03:* `version` en el body también se descarta. `PUT` exige la cabecera `If-Match: "<version>"`.

### Contrato de éxito

| Endpoint             | Código | Body                      |
| -------------------- | ------ | ------------------------- |
| `GET /users`         | 200    | `{ "data": [User, ...] }` — *SPEC 04: una página + `meta`* |
| `GET /users/{id}`    | 200    | `{ "data": User }`        |
| `POST /users`        | 201    | `{ "data": User }`        |
| `PUT /users/{id}`    | 200    | `{ "data": User }`        |
| `DELETE /users/{id}` | 204    | vacío                     |

*SPEC 03:* `GET /users/{id}`, `POST` y `PUT` incluyen además la cabecera `ETag: "<version>"`.

### Contrato de error

```json
{
  "status": 422,
  "message": "Validation failed",
  "errors": { "email": "El email ya está registrado." }
}
```

`errors` siempre presente (`{}` si no hay errores de campo; se serializa como objeto, nunca `[]`).

| Código | `message`                | Cuándo                                                                                   |
| ------ | ------------------------ | ---------------------------------------------------------------------------------------- |
| 400    | `Malformed JSON body`    | JSON inválido, body vacío o JSON que no es objeto                                        |
| 400    | `Invalid query parameters` | *SPEC 04:* query string inválido en `GET /users` (`errors.<param>`)                    |
| 404    | `User not found`         | ID inexistente o `/users/0`                                                              |
| 404    | `Not found`              | Ruta API sin match (`/users/abc`, `/users/-1`, `/users/1/foo`) vía `ApiExceptionHandler` |
| 412    | `Precondition failed`    | *SPEC 03:* `If-Match` no coincide con la versión actual (`errors.version`, `ETag` actual) |
| 415    | `Unsupported media type` | POST/PUT sin `Content-Type: application/json`                                            |
| 422    | `Validation failed`      | Errores de `UserModel` o duplicado `uk_users_email`                                      |
| 428    | `Precondition required`  | *SPEC 03:* `PUT` sin cabecera `If-Match` (`errors.If-Match`)                             |
| 500    | `Internal server error`  | Cualquier excepción no esperada en ruta API                                              |

### Componentes nuevos

```php
// app/Database/DuplicateKeyTranslator.php
final class DuplicateKeyTranslator
{
    /** @param array<string,string> $uniqueKeys constraint => field */
    public static function fieldFor(DatabaseException $e, array $uniqueKeys): ?string;
}

// app/Models/UserModel.php (añadido)
public array $uniqueKeys = ['uk_users_email' => 'email'];

// app/Exceptions/ApiExceptionHandler.php
final class ApiExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface

// app/Config/Exceptions.php (añadido)
public array $apiPrefixes = ['users'];
```

### Variables `.env`

```ini
CORS_ALLOWED_ORIGINS = http://localhost:5173
```

Varios orígenes: `http://localhost:5173,https://app.example.com`. Vacía → ningún origen permitido.

## Implementation plan

**Reglas para todos los pasos:**

- El hook `security-guidance` está activo en cada Edit/Write. Atender y resolver sus avisos antes de cerrar el paso.
- Al terminar cada paso, lanzar el agente `code-simplifier:code-simplifier` sobre los archivos tocados en el paso y aplicar sus sugerencias si no cambian el comportamiento.
- Cada paso termina con `composer test` en verde.
- Tests de API en `tests/feature/UsersApiTest.php`: `DatabaseTestTrait` + `FeatureTestTrait`, `$migrate = true`, `$refresh = true`, `$namespace = 'App'`, grupo `tests` con `DBDebug = true`.

1. **Lectura (GET).** Crear `BaseApiController` con `respondData($data, int $status = 200)` y `respondError(int $status, string $message, array $errors = [])` (`errors` serializado como objeto). Crear `Users` con `index()` y `show($id)`. Rutas `GET /users` y `GET /users/(:num)`. Tests: lista 200 con envelope `data`; detalle 200; ID inexistente y `/users/0` → 404 `User not found` con `errors: {}`.
2. **Excepciones API.** Crear `App\Exceptions\ApiExceptionHandler`; añadir `$apiPrefixes = ['users']` y lógica en `Config\Exceptions::handler()` (prefijo API → handler propio; resto → `ExceptionHandler` por defecto). `PageNotFoundException` → 404 `Not found`; resto → 500 `Internal server error` + `log_message('error', ...)`. Tests: `/users/abc` y `/users/-1` → 404 JSON; ruta API inexistente → 404 JSON; `RuntimeException` vía `Factories::injectMock()` → 500 sin trace, SQL, rutas de archivo ni mensaje original; ruta no API (p. ej. `/no-existe`) no devuelve el contrato JSON.
3. **Creación (POST).** En `BaseApiController`, método `readJsonBody(): array` que corta con 415 (Content-Type ≠ `application/json`) o 400 (JSON malformado, vacío o no objeto). `Users::create()`: inserta, 422 con `errors()` del modelo si falla, 201 con el registro recién leído. Ruta `POST /users`. Tests: 201 + body; 422 por requerido faltante, email inválido y email duplicado (vía `is_unique`); 400 malformado / vacío / array JSON; 415 form-urlencoded; `id` y campos desconocidos en body ignorados.
4. **Duplicado 1062.** Crear `DuplicateKeyTranslator::fieldFor()` (código 1062 + nombre de restricción extraído del mensaje → campo del mapa; si no, `null`). Añadir `$uniqueKeys` a `UserModel`. En `BaseApiController`, envolver escrituras: `DatabaseException` → si el traductor devuelve campo → 422 con el mensaje `is_unique` del modelo; si no → relanzar (→ 500). Tests unitarios `tests/unit/DuplicateKeyTranslatorTest.php` (clave conocida, clave desconocida, otro código). Test feature: `UserModel` con `skipValidation(true)` inyectado vía `Factories::injectMock()` → POST de email existente → 422 `errors.email`, sin texto SQL en la respuesta.
5. **Actualización (PUT).** `Users::update($id)`: 404 si no existe; `readJsonBody()`; reemplazo completo; 422 con errores; 200 con el registro releído. Ruta `PUT /users/(:num)`. Tests: 200; 404; 422 por requerido faltante; 422 por email de otro usuario; 200 con su propio email; 415; 400; `id` del body ignorado (se actualiza el de la URL).
6. **Borrado (DELETE).** `Users::delete($id)`: 404 si no existe; 204 con body vacío. Ruta `DELETE /users/(:num)`. Tests: 204 y body `''`; `GET` posterior → 404; DELETE de inexistente → 404.
7. **CORS.** `Config\Cors`: constructor lee `CORS_ALLOWED_ORIGINS` (split por comas, trim, descarta vacíos, nunca `*`); métodos, cabeceras y `supportsCredentials = false` según §Data model. Aplicar filtro `cors` a `users` y `users/*` en `Config\Filters`. Rutas `OPTIONS /users` y `OPTIONS /users/(:num)`. Añadir `CORS_ALLOWED_ORIGINS` a `.env.example`. Tests: preflight desde `http://localhost:5173` → 204 con `Access-Control-Allow-Origin: http://localhost:5173` y métodos/cabeceras permitidos; origen no permitido → sin `Access-Control-Allow-Origin`; GET simple desde origen permitido incluye la cabecera.
8. **README.** Añadir sección "API": base URL `http://localhost:8080`, tabla endpoints/códigos, ejemplos request/response, contrato de error, CORS, variables `.env` (`database.*`, `CORS_ALLOWED_ORIGINS`), PHP 8.2+.
9. **Revisión de seguridad final.** Ejecutar la skill `security-review` sobre todos los cambios del spec. Corregir hallazgos confirmados y volver a correr `composer test`.

## Acceptance criteria

**Rutas y contrato**

- [ ] `php spark routes` lista exactamente `GET/POST /users`, `GET/PUT/DELETE /users/([0-9]+)` y `OPTIONS` para ambas, más `GET /`.
- [ ] `GET /users` → 200, `Content-Type: application/json`, body `{ "data": [...] }` con todos los usuarios. (*SPEC 04:* primera página de 20 + `meta`; criterios de listado en SPEC 04.)
- [ ] `GET /users/{id}` existente → 200 con `{ "data": {...} }` y los 9 campos del recurso (10 con `version` desde SPEC 03).
- [ ] `GET /users/999999`, `/users/0`, `/users/-1`, `/users/abc` → 404 con `status`, `message` y `errors: {}`.
- [ ] Ruta inexistente bajo `/users` (p. ej. `/users/1/foo`) → 404 JSON con el contrato.
- [ ] Toda respuesta de error trae `status` (igual al código HTTP), `message` y `errors` como objeto JSON (nunca `[]`).

**POST**

- [ ] Body válido → 201 con `{ "data": {...} }` incluyendo `id` > 0 y email normalizado.
- [ ] Falta cualquier requerido → 422 con `errors.<campo>`.
- [ ] Email inválido → 422 con `errors.email`.
- [ ] Email existente (también con mayúsculas/espacios) → 422 con `errors.email = "El email ya está registrado."`.
- [ ] 1062 sobre `uk_users_email` con validación omitida → 422 `errors.email`; la respuesta no contiene `Duplicate entry`, `uk_users_email` ni SQL.
- [ ] JSON malformado, body vacío o JSON array → 400.
- [ ] `Content-Type: application/x-www-form-urlencoded` → 415.
- [ ] `id` y campos desconocidos en el body no afectan la fila creada.

**PUT** (desde SPEC 03 todos los casos envían `If-Match` con la versión actual; los casos de concurrencia están en SPEC 03)

- [ ] Body completo válido → 200 con el registro actualizado (`updated_at` cambia o se mantiene coherente).
- [ ] ID inexistente → 404.
- [ ] Falta cualquier requerido → 422.
- [ ] Email de otro usuario → 422 `errors.email`.
- [ ] Su propio email → 200.
- [ ] `id` distinto en el body → se actualiza solo el registro de la URL.
- [ ] 415 y 400 igual que en POST.

**DELETE**

- [ ] Existente → 204 con body vacío (longitud 0).
- [ ] `GET` posterior al mismo ID → 404.
- [ ] Inexistente → 404 JSON.

**Errores 500 y handler**

- [ ] Excepción inesperada simulada con `Factories::injectMock()` → 500 `{ "status": 500, "message": "Internal server error", "errors": {} }`.
- [ ] La respuesta 500 no contiene trace, nombre de clase de excepción, mensaje original, rutas de archivo ni SQL, con `CI_ENVIRONMENT = development` y `testing`.
- [ ] El detalle de la excepción queda en `writable/logs/` vía `log_message('error', ...)`.
- [ ] Ruta no API inexistente no devuelve el contrato JSON (handler por defecto de CI4).

**CORS**

- [ ] Preflight `OPTIONS /users` con `Origin: http://localhost:5173` → 204 con `Access-Control-Allow-Origin: http://localhost:5173`, métodos `GET, POST, PUT, DELETE, OPTIONS` y cabeceras `Content-Type, Authorization, If-Match`.
- [ ] Origen no listado → respuesta sin `Access-Control-Allow-Origin`.
- [ ] Ninguna respuesta contiene `Access-Control-Allow-Origin: *` ni `Access-Control-Allow-Credentials: true`.
- [ ] `CORS_ALLOWED_ORIGINS` con dos orígenes separados por coma permite ambos.

**Calidad y entrega**

- [ ] `Users.php` no contiene SQL, Query Builder, códigos MySQL ni `DatabaseException`.
- [ ] `DuplicateKeyTranslator` es el único archivo de `app/` que referencia el código `1062`.
- [ ] `tests/unit/DuplicateKeyTranslatorTest.php` cubre: clave conocida → campo, clave desconocida → `null`, otro código → `null`.
- [ ] `composer test` pasa en verde.
- [ ] `.env.example` contiene `CORS_ALLOWED_ORIGINS = http://localhost:5173` y ninguna credencial real.
- [ ] README tiene sección "API" con base URL, endpoints, códigos, ejemplos, contrato de error, CORS, variables `.env` y PHP 8.2+.
- [ ] `code-simplifier` ejecutado al final de cada paso del plan.
- [ ] `security-review` ejecutado al final; sin hallazgos confirmados abiertos.
- [ ] No se añadieron dependencias a `composer.json`.

## Decisions

- **Yes:** envelope `{ "data": ... }` en todas las respuestas de éxito. Permite añadir `meta` de paginación sin romper el contrato.
- **No:** recurso directo sin envelope. Añadir paginación después rompería el frontend.
- **Yes:** contrato de error propio `{ status, message, errors }` con `errors` siempre objeto. El frontend lee siempre la misma forma.
- **No:** formato por defecto de `failValidationErrors` de CI4 (`error`, `messages`). Inconsistente con el resto de errores.
- **Yes:** PUT = reemplazo completo, mismos requeridos que POST. Semántica REST y formulario de edición que envía todo.
- **No:** PUT parcial. Si hace falta, se añade `PATCH` en otro spec.
- **Yes:** rutas explícitas con `(:num)`, sin prefijo `/api`. Coincide con la prueba; IDs no numéricos no llegan al controlador.
- **No:** `$routes->resource()`. Genera rutas extra (`new`, `edit`, `PATCH`) fuera del contrato.
- **Yes:** solo `application/json` en POST/PUT; 415 si no. Un único formato de entrada, menos superficie.
- **Yes:** 400 para JSON malformado, vacío o no objeto; 422 solo para errores de validación sobre un objeto válido. Distingue error de sintaxis de error de datos. Amplía los códigos de la prueba de forma deliberada.
- **Yes:** `id` del body ignorado; el ID sale siempre de la URL. Evita asignación masiva sobre la PK.
- **Yes:** `BaseApiController` centraliza respuestas, lectura de JSON y captura de `DatabaseException`. Un solo punto; futuros controladores heredan.
- **No:** trait `ApiResponder`. Mismo efecto, pero la captura de escrituras encaja mejor como clase base.
- **No:** `UserService`. CRUD sin reglas de negocio; añadirla ahora es complejidad sin uso.
- **Yes:** `DuplicateKeyTranslator` como único conocedor de MySQL 1062, identificando la restricción por nombre. La UNIQUE de BD es la garantía final ante carreras; el controlador queda agnóstico del motor.
- **No:** sobrescribir `insert()`/`update()` del Model para convertir 1062. Mezcla persistencia con traducción a HTTP.
- **No:** `DBDebug = false` para leer `db->error()`. Silenciaría el resto de errores de BD.
- **No:** tratar cualquier 1062 como email duplicado. Futuras UNIQUE darían errores engañosos.
- **Yes:** `Config\Exceptions::handler()` devolviendo `ApiExceptionHandler` solo para prefijos en `$apiPrefixes`. Mecanismo nativo de CI 4.7; el resto de la app conserva el handler por defecto.
- **No:** confiar solo en `CI_ENVIRONMENT = production` para ocultar traces. La API no debe filtrar detalles en ningún entorno.
- **No:** handler global sustituyendo al de CI4 para toda la app. Afectaría páginas no API.
- **Yes:** CORS con `Config\Cors` + filtro `cors` nativo, orígenes desde `CORS_ALLOWED_ORIGINS`. Sin duplicar lógica; configurable por entorno sin tocar código.
- **No:** `Access-Control-Allow-Origin: *`. Incompatible con credenciales futuras y demasiado permisivo.
- **Yes:** `supportsCredentials = false`. No hay autenticación todavía.
- **Yes:** `If-Match` y `Authorization` ya en cabeceras permitidas. Prepara concurrencia y auth sin implementarlas. (La concurrencia se implementó en SPEC 03.)
- **Yes:** PHP 8.2+ y CI 4.7.6. Lo exigen `composer.json` y el framework instalado; la prueba pide 8.1+, no alcanzable sin degradar dependencias.
- **Yes:** tests feature contra BD `tests` y unit para el traductor. Cubren el contrato real HTTP + BD y la lógica aislada de MySQL.
- **Yes:** 500 y 1062 simulados vía `Factories::injectMock()`. Reproducible sin depender de carreras reales.
- **Yes:** `code-simplifier` al final de cada paso, hook `security-guidance` activo en cada edición, `security-review` al final del spec. Requisito del usuario.
- **Yes:** README solo con sección "API". Instalación completa backend + frontend va en otro spec.

## Risks

| Risk                                                                                        | Mitigation                                                                                                                                       |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Formato del mensaje MySQL 1062 cambia y el traductor no extrae el nombre de la restricción  | Devuelve `null` → la excepción sube a 500 (fallo seguro, sin datos filtrados). Test unitario con mensaje real de MySQL 8.4 detecta la regresión. |
| `DBDebug = false` en algún entorno: `insert()` devuelve `false` sin excepción               | `.env.example` y `Config\Database` mantienen `DBDebug = true`. Documentado en README.                                                            |
| `getJSON()` de CI4 cambia su comportamiento ante JSON inválido en un upgrade                | Tests de 400 (malformado, vacío, array) fallan y avisan.                                                                                         |
| `errors` vacío se serializa como `[]` en vez de `{}`                                        | `respondError()` fuerza objeto. Test comprueba `errors: {}` literal en el body.                                                                  |
| El handler de excepciones API captura rutas no API por prefijo ambiguo (p. ej. `/usersfoo`) | Coincidencia por segmento completo (`users` o `users/...`), no por `str_starts_with` simple. Test de ruta no API.                                |
| `CORS_ALLOWED_ORIGINS` vacía o ausente                                                      | Ningún origen permitido (fallo cerrado). README indica configurarla.                                                                             |
| Filtro `cors` no aplicado a OPTIONS y el preflight devuelve 404                             | Rutas `OPTIONS` explícitas + test de preflight.                                                                                                  |
| Logs del 500 contienen datos personales del body                                            | Se loguea clase, mensaje y trace de la excepción, no el body de la petición.                                                                     |
| `Factories::injectMock()` persiste entre tests                                              | `Factories::reset()` en `tearDown()`.                                                                                                            |

## What is **not** in this spec

- Autenticación, JWT, sesiones, CSRF.
- Paginación, búsqueda, ordenación. → SPEC 04.
- Control de concurrencia (`If-Match`/`ETag`, 409/412). → SPEC 03.
- `PATCH` / actualización parcial.
- `UserService`.
- `supportsCredentials = true`.
- Rate limiting.
- README completo de instalación backend + frontend.
- Frontend React.
- Cambios en migración, seeder o Docker.

Cada uno, si llega, va en su propio spec.
