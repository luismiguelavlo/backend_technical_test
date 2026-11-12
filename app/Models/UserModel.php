<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Users persistence (docs/database-design.md §6).
 */
class UserModel extends Model
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['first_name', 'last_name', 'email', 'gender', 'telephone', 'age'];

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
