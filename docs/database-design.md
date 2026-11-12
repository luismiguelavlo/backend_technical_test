# Diseño de Base de Datos — User Management API

> Basado en `docs/Developer_Technical_Test.md` (Sección 2 — Database / Backend).

| Ítem | Valor |
| --- | --- |
| Motor | MySQL 8.x (InnoDB) — driver CI4 `MySQLi` |
| Charset / Collation | `utf8mb4` / `utf8mb4_unicode_ci` |
| BD principal | `ci4` (`database.default.*` en `.env`) |
| BD de pruebas | `ci4_test` (`database.tests.*` en `.env`) |
| Dominio | Gestión de usuarios (CRUD REST consumido por React) |
| Entidades | 1 — `users` |

---

## 1. Diagrama Entidad-Relación

```
┌──────────────────────────────────────────┐
│                  users                   │
├──────────────────────────────────────────┤
│ id          INT UNSIGNED  PK, AI         │
│ first_name  VARCHAR(100)  NOT NULL       │
│ last_name   VARCHAR(100)  NOT NULL       │
│ email       VARCHAR(254)  NOT NULL, UQ   │
│ gender      ENUM(...)     NOT NULL       │
│ telephone   VARCHAR(20)   NULL           │
│ age         TINYINT UNS.  NOT NULL       │
│ created_at  DATETIME      NULL           │
│ updated_at  DATETIME      NULL           │
└──────────────────────────────────────────┘
```

Sin relaciones: el alcance de la prueba define una sola tabla. No hay claves foráneas.

---

## 2. Diccionario de datos

| Columna | Tipo | Nulo | Default | Restricción | Regla del spec | Justificación |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | No | AUTO_INCREMENT | PK | primary key, auto-increment | El spec exige auto-increment (no UUID). `UNSIGNED` duplica el rango (~4.29 B). |
| `first_name` | `VARCHAR(100)` | No | — | — | required | 100 cubre nombres compuestos largos. |
| `last_name` | `VARCHAR(100)` | No | — | — | required | Ídem. |
| `email` | `VARCHAR(254)` | No | — | `UNIQUE` (`uk_users_email`) | required, valid format, unique | 254 = longitud máxima práctica (RFC 5321). Unicidad garantizada en BD **y** en validación. |
| `gender` | `ENUM('male','female','other')` | No | — | dominio cerrado | required | Conjunto fijo → ENUM valida a nivel BD y ocupa 1 byte. *(Supuesto: el spec no lista valores.)* |
| `telephone` | `VARCHAR(20)` | **Sí** | `NULL` | — | optional | String, no numérico: conserva `+`, ceros iniciales y formato E.164 (máx. 15 dígitos + símbolos). |
| `age` | `TINYINT UNSIGNED` | No | — | 0–255 en BD; 1–120 en validación | required | 1 byte, suficiente para edad humana. |
| `created_at` | `DATETIME` | Sí | `NULL` | — | — (extra) | Auditoría básica; CI4 lo llena con `$useTimestamps = true`. |
| `updated_at` | `DATETIME` | Sí | `NULL` | — | — (extra) | Ídem; base para control de concurrencia optimista (ver §7). |

### Supuestos documentados
1. **`gender`** — valores `male | female | other`. Si se requieren más (p. ej. `prefer_not_to_say`), se agrega con una nueva migración.
2. **`age`** — entero; rango válido 1–120 (validación en el Model).
3. **`telephone`** — opcional; si se envía, solo `0-9 + - ( ) espacio`, máx. 20 caracteres.
4. **Sin soft delete** — `DELETE /users/{id}` responde 204 y luego `GET` debe dar 404. Un `deleted_at` complicaría la unicidad de `email` (un email "borrado" bloquearía su reutilización) sin aportar valor al alcance pedido.
5. **Email** — se normaliza a minúsculas + `trim` antes de guardar; la collation `_ci` ya hace la comparación case-insensitive para el índice único.

---

## 3. DDL (SQL)

```sql
-- users
-- Propósito: usuarios gestionados por la app React vía API REST CI4.

CREATE TABLE users (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    first_name  VARCHAR(100)      NOT NULL,
    last_name   VARCHAR(100)      NOT NULL,
    email       VARCHAR(254)      NOT NULL,
    gender      ENUM('male','female','other') NOT NULL,
    telephone   VARCHAR(20)       NULL DEFAULT NULL,
    age         TINYINT UNSIGNED  NOT NULL,
    created_at  DATETIME          NULL DEFAULT NULL,
    updated_at  DATETIME          NULL DEFAULT NULL,

    CONSTRAINT pk_users        PRIMARY KEY (id),
    CONSTRAINT uk_users_email  UNIQUE (email),
    CONSTRAINT ck_users_age    CHECK (age BETWEEN 1 AND 120)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

> `CHECK` se aplica en MySQL ≥ 8.0.16. En versiones anteriores se ignora sin error; la validación del Model sigue siendo la fuente principal.

---

## 4. Índices

| Índice | Columnas | Tipo | Motivo |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | PK clustered | `GET/PUT/DELETE /users/{id}`. |
| `uk_users_email` | `email` | UNIQUE | Regla "email único"; también acelera la regla `is_unique` de validación. |

No se crean más índices ahora: con ≥10 registros y sin búsqueda/orden en el alcance, serían costo de escritura sin beneficio.

**Índices futuros** (escenario Q2 — búsqueda por nombre, orden y paginación con ~500k filas):

| Índice propuesto | Uso |
| --- | --- |
| `idx_users_last_first (last_name, first_name, id)` | Búsqueda por prefijo (`LIKE 'gar%'`) y orden por apellido; `id` como desempate para keyset pagination. |
| `idx_users_created_id (created_at, id)` | Orden por fecha de alta + keyset pagination. |
| `FULLTEXT ft_users_name (first_name, last_name)` | Solo si se requiere búsqueda por subcadena/palabra (`LIKE '%x%'` no usa B-tree). |

Criterio: indexar solo columnas presentes en `WHERE` / `ORDER BY` de consultas reales, verificado con `EXPLAIN`.

---

## 5. Migración CodeIgniter 4

`app/Database/Migrations/YYYY-MM-DD-HHMMSS_CreateUsersTable.php`
(generar con `php spark make:migration CreateUsersTable`)

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'first_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'last_name'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 254],
            'gender'     => [
                'type'       => 'ENUM',
                'constraint' => ['male', 'female', 'other'],
            ],
            'telephone' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'default'    => null,
            ],
            'age' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email', 'uk_users_email');

        $this->forge->createTable('users', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('users', true);
    }
}
```

> Forge no soporta `CHECK`; si se quiere en BD, agregar tras `createTable`:
> `$this->db->query('ALTER TABLE users ADD CONSTRAINT ck_users_age CHECK (age BETWEEN 1 AND 120)');`

Comandos:

```bash
php spark migrate              # up
php spark migrate:rollback     # down
php spark migrate:refresh      # down + up
```

Rollback SQL equivalente:

```sql
DROP TABLE IF EXISTS users;
```

---

## 6. Reglas de validación (Model)

La BD es la última barrera; la validación del Model produce los errores 422 legibles para el frontend.

`app/Models/UserModel.php` (extracto):

```php
protected $table          = 'users';
protected $primaryKey     = 'id';
protected $returnType     = 'array';
protected $useTimestamps  = true;
protected $allowedFields  = ['first_name', 'last_name', 'email', 'gender', 'telephone', 'age'];

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

- `is_unique[users.email,id,{id}]` excluye el propio registro en `PUT` (el placeholder `{id}` requiere que `id` esté en los datos validados).
- Mapeo de errores: violación de validación → `422`; registro inexistente → `404`.

| Regla del spec | BD | Model |
| --- | --- | --- |
| Campos requeridos | `NOT NULL` | `required` |
| Email formato válido | — | `valid_email` |
| Email único | `UNIQUE uk_users_email` | `is_unique[...]` |
| Teléfono opcional | `NULL` | `permit_empty` |

---

## 7. Seeder

`app/Database/Seeds/UserSeeder.php` — ≥10 usuarios (spec), generados con `fakerphp/faker` usando `unique()->safeEmail()` para no violar `uk_users_email`; `gender` aleatorio de la lista ENUM; `age` 18–80; `telephone` nulo en ~30 % de los casos para cubrir el campo opcional.

```bash
php spark db:seed UserSeeder
```

---

## 8. Notas de diseño y evolución

**Integridad**
- Doble capa: restricciones en BD (`NOT NULL`, `UNIQUE`, `ENUM`, `CHECK`) + validación en Model.
- Unicidad de email por índice: protege contra condiciones de carrera que `is_unique` solo no evita (dos `POST` simultáneos). Capturar el error de clave duplicada (MySQL 1062) y responder 422.

**Concurrencia (escenario Q3)**
- Control optimista sin cambiar esquema: el cliente envía el `updated_at` leído (o un `ETag` derivado) en `If-Match`; el `UPDATE ... WHERE id = ? AND updated_at = ?` afecta 0 filas si otro agente guardó antes → `409/412`.
- Alternativa más robusta: columna `version INT UNSIGNED NOT NULL DEFAULT 1` incrementada en cada update (evita colisiones de resolución de segundo en `DATETIME`).

**Rendimiento (escenario Q2)**
- `LIMIT/OFFSET` profundo escanea y descarta `OFFSET` filas → degrada linealmente. Usar keyset pagination: `WHERE (last_name, id) > (?, ?) ORDER BY last_name, id LIMIT 20`, apoyado en `idx_users_last_first`.

**Escalabilidad**
- Una tabla de este tamaño no requiere particionado ni sharding. Si crece la lectura: réplicas de lectura + caché del listado.

**Configuración**
- Credenciales solo en `.env` (`database.default.*`, `database.tests.*`); nunca en código. `.env.example` documenta las claves sin valores reales.
