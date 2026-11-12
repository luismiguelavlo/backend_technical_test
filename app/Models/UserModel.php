<?php

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * Users persistence (docs/database-design.md §6).
 */
class UserModel extends Model
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    /**
     * `version` is server-owned (optimistic locking): controllers strip it from
     * request bodies; only updateIfVersion() writes it.
     */
    protected $allowedFields = ['first_name', 'last_name', 'email', 'gender', 'telephone', 'age', 'version'];

    /**
     * UNIQUE constraint name => field, used to translate duplicate-key DB
     * errors into field validation errors (App\Database\DuplicateKeyTranslator).
     *
     * @var array<string, string>
     */
    public array $uniqueKeys = ['uk_users_email' => 'email'];

    protected $validationRules = [
        'id'         => 'permit_empty|is_natural_no_zero',
        'first_name' => 'required|string|max_length[100]',
        'last_name'  => 'required|string|max_length[100]',
        'email'      => 'required|valid_email|max_length[254]|is_unique[users.email,id,{id}]',
        'gender'     => 'required|in_list[male,female,other]',
        'telephone'  => 'permit_empty|max_length[20]|regex_match[/^[0-9+\-() ]+$/]',
        'age'        => 'required|is_natural_no_zero|less_than_equal_to[120]',
        'version'    => 'permit_empty|is_natural_no_zero',
    ];

    protected $validationMessages = [
        'email' => [
            'is_unique'   => 'El email ya está registrado.',
            'valid_email' => 'El email no tiene un formato válido.',
        ],
        'gender' => [
            'in_list' => 'El género debe ser uno de: male, female, other.',
        ],
        'age' => [
            'less_than_equal_to' => 'La edad no puede ser mayor que 120.',
            'is_natural_no_zero' => 'La edad debe ser un número entero mayor que 0.',
        ],
    ];

    /**
     * Normalizes email before validation (BaseModel validates before
     * running beforeInsert callbacks, so callbacks would be too late).
     *
     * @param array|object|null $row
     *
     * @return bool|int|string
     */
    public function insert($row = null, bool $returnID = true)
    {
        if (is_array($row)) {
            $row = $this->normalizeEmail($row);
        }

        return parent::insert($row, $returnID);
    }

    /**
     * Normalizes email and injects `id` so `is_unique[users.email,id,{id}]`
     * excludes the record being updated. `id` is not in $allowedFields, so
     * it is discarded before persisting.
     *
     * @param array|int|string|null $id
     * @param array|object|null     $row
     */
    public function update($id = null, $row = null): bool
    {
        if (is_array($row)) {
            $row = $this->normalizeEmail($row);

            if (is_scalar($id)) {
                $row['id'] = $id;
            }
        }

        return parent::update($id, $row);
    }

    /**
     * Optimistic-lock update: applies $row only if the stored `version` is still
     * $expectedVersion, and increments it, in a single
     * `UPDATE ... WHERE id = ? AND version = ?` (no read-then-write race).
     *
     * @param int|string           $id
     * @param array<string, mixed> $row
     *
     * @return false|int false on validation errors (see errors()); otherwise the
     *                   affected rows: 1 = updated, 0 = version mismatch (stale)
     */
    public function updateIfVersion($id, array $row, int $expectedVersion): false|int
    {
        $row['version'] = $expectedVersion + 1;

        $this->builder()->where('version', $expectedVersion);

        try {
            $updated = $this->update($id, $row);
        } catch (Throwable $e) {
            $this->builder()->resetQuery();

            throw $e;
        }

        if ($updated === false) {
            // Validation failed before the query ran: drop the pending WHERE.
            $this->builder()->resetQuery();

            return false;
        }

        // `version` always changes on a match, so 0 rows can only mean a mismatch.
        return $this->db->affectedRows();
    }

    /**
     * trim + lowercase `email` when it is a string.
     */
    private function normalizeEmail(array $row): array
    {
        if (isset($row['email']) && is_string($row['email'])) {
            $row['email'] = strtolower(trim($row['email']));
        }

        return $row;
    }
}
