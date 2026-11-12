<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indexes for GET /users sorting + keyset pagination (specs/04-users-list-query.md).
 *
 * One `(column, id)` index per sortable column: it serves `ORDER BY column, id`,
 * the keyset range `(column, id) > (?, ?)` and prefix search `column LIKE 'x%'`.
 * `id` is the PK and `email` already has uk_users_email (unique, so it needs
 * no tie-breaker).
 */
class AddListIndexesToUsers extends Migration
{
    private const INDEXES = [
        'idx_users_last_name_id'  => ['last_name', 'id'],
        'idx_users_first_name_id' => ['first_name', 'id'],
        'idx_users_age_id'        => ['age', 'id'],
    ];

    public function up()
    {
        foreach (self::INDEXES as $name => $columns) {
            $this->forge->addKey($columns, false, false, $name);
        }

        $this->forge->processIndexes('users');
    }

    public function down()
    {
        foreach (array_keys(self::INDEXES) as $name) {
            $this->forge->dropKey('users', $name, false);
        }
    }
}
