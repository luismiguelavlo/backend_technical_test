# SPEC 04 — Búsqueda, filtro, orden y paginación keyset en GET /users

> **Status:** Implemented · **Depends on:** SPEC 01, SPEC 02 · **Date:** 2026-11-12
> **Objective:** Que `GET /users` devuelva una página filtrada y ordenada por la base de datos, con paginación por cursor (keyset) cuyo costo no crece con la profundidad de la página, para soportar el escenario Q2 de la prueba (~500.000 filas).

## Scope

**In:**

- `app/Libraries/UserListQuery.php`: parsea y valida el query string (`q`, `gender`, `sort`, `dir`, `limit`, `cursor`, `with_total`). Lanza `InvalidListQueryException` con los errores por parámetro. Codifica y decodifica el cursor.
- `app/Libraries/InvalidListQueryException.php`.
- `UserModel::listPage(UserListQuery)`: filtros, keyset, `ORDER BY sort, id`, `LIMIT n+1` y `COUNT` opcional.
- `Users::index()`: 400 si los parámetros no son válidos; si son válidos, `{ data, meta }`.
- `BaseApiController::respondData()`: parámetro opcional `$meta`.
- Migración `AddListIndexesToUsers`: `idx_users_last_name_id (last_name, id)`, `idx_users_first_name_id (first_name, id)`, `idx_users_age_id (age, id)`.
- Tests de feature en `UsersApiTest`: recorrer todas las páginas sin repetir ni saltar filas, empates resueltos por `id`, `asc`/`desc`, búsqueda por prefijo sin distinguir mayúsculas ni acentos, varios términos, comodines literales, filtro de género, `total`, parámetros inválidos y cursores manipulados o de otro orden.
- Docs: sección "Listing" y el 400 en el README, enmiendas en SPEC 02 y `docs/database-design.md` §4.
- Frontend (`front-test`, otro repo):
  - `useUsers(params)` con una pila de cursores; buscador con debounce de 300 ms.
  - Encabezados de columna ordenables.
  - Paginación anterior/siguiente.
  - `total` solo en la primera página.

**Out of scope (for future specs):**

- Búsqueda por subcadena (`LIKE '%x%'`) o por relevancia: requiere `FULLTEXT` o un motor de búsqueda.
- Saltar a una página N arbitraria: keyset no lo permite sin `OFFSET`.
- `prev_cursor` en la API: el cliente guarda la pila de cursores.
- Orden por `created_at` (columna nullable; complica el keyset) o por `gender`/`telephone`.
- Conteo aproximado (`information_schema.TABLES.TABLE_ROWS`) o cacheado.
- Índices compuestos con `gender` para filtrar y ordenar a la vez.

## Data model

### Query string

| Parámetro    | Regla                                                        | Default |
| ------------ | ------------------------------------------------------------ | ------- |
| `q`          | string ≤ 100; se divide por espacios, máx. 5 términos        | —       |
| `gender`     | `male` \| `female` \| `other`                                | todos   |
| `sort`       | `id` \| `first_name` \| `last_name` \| `email` \| `age` (whitelist) | `id` |
| `dir`        | `asc` \| `desc`                                              | `asc`   |
| `limit`      | entero 1–100                                                 | 20      |
| `cursor`     | base64url de `{"s":sort,"d":dir,"v":valor,"id":id}`          | —       |
| `with_total` | `1` \| `true`                                                | off     |

Parámetro repetido o en array (`q[]=…`) → error de ese parámetro.

### SQL generado (`sort=last_name&dir=asc`, segunda página)

```sql
SELECT * FROM users
WHERE gender = ?                                              -- opcional
  AND (first_name LIKE 't%' OR last_name LIKE 't%' OR email LIKE 't%')   -- por término
  AND (last_name > :v OR (last_name = :v AND id > :id))       -- cursor
ORDER BY last_name ASC, id ASC
LIMIT :limit + 1
```

- `desc` invierte los operadores (`<`) y el orden.
- Con `sort=id`, el cursor es solo `id > :id`.
- Se usa la forma expandida en vez de `(last_name, id) > (?, ?)`, porque MySQL no siempre convierte la comparación de tuplas en un rango de índice.
- `LIMIT n+1`: si llega la fila extra, existe página siguiente y `next_cursor` apunta a la última fila devuelta.

### Respuesta

```json
{ "data": [User, ...], "meta": { "limit": 20, "sort": "id", "dir": "asc", "next_cursor": "…" | null, "total": 57 | null } }
```

Error: `400 Invalid query parameters`, `errors: { "<param>": "<mensaje>" }`.

### Índices

| Índice                    | Columnas            | Sirve a                                                       |
| ------------------------- | ------------------- | ------------------------------------------------------------- |
| `PRIMARY`                 | `id`                | `sort=id` + keyset                                            |
| `uk_users_email`          | `email`             | `sort=email` (único: no necesita desempate) y prefijo de email |
| `idx_users_last_name_id`  | `last_name, id`     | `sort=last_name` + keyset y `last_name LIKE 'x%'`             |
| `idx_users_first_name_id` | `first_name, id`    | `sort=first_name` + keyset y `first_name LIKE 'x%'`           |
| `idx_users_age_id`        | `age, id`           | `sort=age` + keyset                                           |

## Implementation plan

1. **Query object.** `UserListQuery::fromArray()`: validación de cada parámetro, términos de búsqueda y cursor ligado a `sort`/`dir`; `cursorAfter($row)`. Verificación: tests de 400 por parámetro.
2. **Modelo.**
   - `listPage()` con un builder nuevo (`db->table()`) por consulta, para no compartir estado.
   - `applyFilters()`: el escape de `%`, `_` y `!` es manual, porque CI 4.7 emite `ESCAPE '!'` sin escapar el valor (verificado: `q=%` compilaba a `LIKE '%%'`).
   - `applyKeyset()` con la forma expandida.
3. **Controlador.** `index()` captura `InvalidListQueryException` y responde 400; si no hay error, `respondData($rows, 200, $meta)`.
4. **Migración de índices** con `forge->addKey()` + `processIndexes()`; `down()` con `dropKey()`. Verificación: `SHOW INDEX FROM users`.
5. **Tests.** Adaptar los dos tests de índice (`meta` en el envelope), cambiar el mock del test 500 a `listPage` y añadir los casos de §Acceptance criteria. Verificación: `composer test` en verde.
6. **Docs:** README, SPEC 02 y `docs/database-design.md`.
7. **Frontend:**
   - `listUsers(params, { cursor, withTotal })`.
   - `useUsers(params)`: pila de cursores, reset al cambiar la consulta, `AbortController` por petición, vuelta a la página anterior si la página quedó vacía.
   - `useDebouncedValue`; `DataTable` con `sortKey`/`aria-sort`.
   - `Pagination` anterior/siguiente.
   - Se eliminan el filtrado y la paginación en el cliente (`utils/userFilters.ts`, `hooks/usePagination.ts`).
   - Verificación: `npm run build` y `npm run lint` en verde.

## Acceptance criteria

**Paginación**

- [ ] Sin parámetros → 200, `data` con hasta 20 usuarios por `id asc` y `meta = { limit: 20, sort: "id", dir: "asc", next_cursor, total: null }`.
- [ ] Seguir `next_cursor` hasta `null` devuelve todos los usuarios una sola vez y en orden, en `asc` y en `desc`.
- [ ] Con empates en la columna de orden (mismo `last_name`), el orden es estable por `id` entre páginas.
- [ ] `sort=age` ordena por edad.
- [ ] `with_total=1` → `meta.total` = número de coincidencias (no el tamaño de la página); 0 sin usuarios.

**Búsqueda y filtro**

- [ ] `q` coincide por prefijo de nombre, apellido o email, sin distinguir mayúsculas ni acentos (`LUCI` → "Lucía", `perez` → "Pérez").
- [ ] Un texto en medio de la palabra no coincide (`ucia` no encuentra "Lucía").
- [ ] Varios términos se combinan con AND (`ana gar` → solo "Ana García").
- [ ] `%`, `_` y `!` son literales (`q=%` no devuelve todo; `100%` no coincide con "1000").
- [ ] `gender` se combina con `q` y con `total`.

**Errores**

- [ ] `sort` fuera de la whitelist (incluida una inyección SQL), `dir`, `limit` 0/101/no numérico, `gender` inválido, `q` en array o > 100 caracteres, cursor basura, cursor de otro `sort` y cursor con un `v` de tipo incorrecto → 400 `Invalid query parameters` con `errors.<param>`.
- [ ] Una excepción inesperada en `listPage` → 500 genérico (test 500 existente).

**Calidad**

- [ ] `Users.php` sin SQL ni Query Builder.
- [ ] La columna de orden nunca proviene directamente de la entrada del usuario (whitelist).
- [ ] `composer test` en verde.

## Decisions

- **Yes:** keyset (cursor) pagination.
  - `OFFSET 100000` obliga a MySQL a leer y descartar 100.000 filas: cada página es más lenta que la anterior.
  - Keyset hace un salto en el índice y lee `limit+1` filas, a cualquier profundidad.
  - Además es estable ante inserciones y borrados entre páginas: no repite ni salta filas.
- **No:** `LIMIT/OFFSET`. Es simple y permite saltar a la página N, pero degrada linealmente (escenario Q2) e inserciones concurrentes desplazan filas entre páginas.
- **Yes:** `id` como desempate en todo orden e índice `(columna, id)`. Sin desempate, filas con el mismo valor podrían repetirse o perderse entre páginas.
- **Yes:** cursor opaco (base64url de JSON) ligado a `sort` + `dir` y validado en tipo. Si cambia el orden, un cursor viejo daría páginas incoherentes; rechazarlo con 400 es explícito.
- **No:** firmar el cursor con HMAC. Un cursor manipulado solo cambia el punto de inicio de una lista que el cliente ya puede leer completa; los valores van como parámetros enlazados (bind), sin riesgo de inyección.
- **Yes:** búsqueda por prefijo (`LIKE 'x%'`). Puede usar los índices B-tree; con la collation `utf8mb4_unicode_ci` no distingue mayúsculas ni acentos.
- **No:** `LIKE '%x%'`. No puede usar índices: con 500.000 filas es un full scan en cada tecla. Si hace falta buscar por subcadena, `FULLTEXT` o un motor de búsqueda (otro spec).
- **Trade-off conocido:** el `OR` entre tres columnas puede impedir que MySQL use un solo índice (puede recurrir a index merge o a un scan). A gran escala la alternativa es `FULLTEXT(first_name, last_name, email)` o una columna normalizada con su índice.
- **Yes:** `total` opcional (`with_total=1`). Un `COUNT(*)` exacto recorre todas las coincidencias: pedirlo en cada página duplicaría el costo. El frontend lo pide una vez por búsqueda.
- **Yes:** `UserListQuery` en `app/Libraries` (validación de la petición) separado del modelo (consulta) y del controlador (HTTP). Coincide con la respuesta a Q1: la validación de entrada no vive en el controlador ni en el modelo.
- **Yes:** 400 (no 422) para query strings inválidos. 422 queda para bodies sintácticamente correctos con datos inválidos.
- **Yes:** límite de 1 a 100. Evita que un cliente pida la tabla entera de una vez.
- **Yes:** los índices se crean en una migración aparte, no editando la migración original de SPEC 01: así las bases ya migradas los reciben.

## Risks

| Risk                                                                     | Mitigation                                                                                                  |
| ------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| CI4 no escapa los comodines en `like()` (verificado en 4.7.6)            | Escape manual con `likeEscapeChar`; tests `testSearchEscapesLikeWildcards` y `testSearchTreatsWildcardsLiterally`. |
| Con pocas filas, MySQL ignora los índices (full scan + filesort)         | Esperado: el optimizador elige por costo. La ganancia se verifica con `EXPLAIN` sobre un volumen real.      |
| Orden distinto entre la comparación del cursor y el `ORDER BY` (collation) | Ambos usan la collation de la columna; el test con empates y acentos lo cubre.                             |
| La fila del cursor se borra entre páginas                                | El keyset compara valores, no posiciones: la página siguiente empieza en el siguiente valor existente.     |
| Una edición cambia el valor ordenado de una fila ya vista                | La fila puede aparecer de nuevo o no aparecer en páginas siguientes; es aceptable en un listado administrativo. |
| El `total` del frontend queda desactualizado tras crear o borrar          | El cliente ajusta ±1 y vuelve a contar en la siguiente primera página.                                      |

## What is **not** in this spec

- Búsqueda por subcadena o `FULLTEXT`.
- Saltar a la página N.
- `prev_cursor` en la API.
- Orden por `created_at`, `gender` o `telephone`.
- Conteo aproximado o cacheado.
- Caché HTTP del listado.

Cada uno, si llega, va en su propio spec.
