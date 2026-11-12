<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds `version` for optimistic concurrency control (specs/03-optimistic-concurrency.md).
 * Existing rows start at 1; every successful PUT increments it.
 */
class AddVersionToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
                'after'    => 'age',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'version');
    }
}
