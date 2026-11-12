<?php

namespace App\Models;

use App\Libraries\UserListQuery;
use CodeIgniter\Database\BaseBuilder;
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
     * Normalizes input before validation (BaseModel validates before
     * running beforeInsert callbacks, so callbacks would be too late).
     *
     * @param array|object|null $row
     *
     * @return bool|int|string
     */
    public function insert($row = null, bool $returnID = true)
    {
        if (is_array($row)) {
            $row = $this->normalize($row);
        }

        return parent::insert($row, $returnID);
    }

    /**
     * Normalizes input and injects `id` so `is_unique[users.email,id,{id}]`
     * excludes the record being updated. `id` is not in $allowedFields, so
     * it is discarded before persisting.
     *
     * @param array|int|string|null $id
     * @param array|object|null     $row
     */
    public function update($id = null, $row = null): bool
    {
        if (is_array($row)) {
            $row = $this->normalize($row);

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
     * One page of the users list with keyset pagination (specs/04):
     * `WHERE <filters> AND (sort, id) after cursor ORDER BY sort, id LIMIT n+1`.
     * Cost does not grow with the page depth (no OFFSET).
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null, total: int|null}
     */
    public function listPage(UserListQuery $query): array
    {
        // Exact count only on request: it scans every matching row.
        $total = null;
        if ($query->withTotal) {
            $countBuilder = $this->db->table($this->table);
            $this->applyFilters($countBuilder, $query);
            $total = $countBuilder->countAllResults();
        }

        $builder = $this->db->table($this->table);
        $this->applyFilters($builder, $query);

        if ($query->after !== null) {
            $this->applyKeyset($builder, $query->sort, $query->dir, $query->after);
        }

        if ($query->sort !== 'id') {
            $builder->orderBy($query->sort, $query->dir);
        }

        // One extra row tells whether there is a next page.
        $rows = $builder->orderBy('id', $query->dir)->limit($query->limit + 1)->get()->getResultArray();

        $nextCursor = null;
        if (count($rows) > $query->limit) {
            array_pop($rows);
            $nextCursor = $query->cursorAfter($rows[array_key_last($rows)]);
        }

        return ['rows' => $rows, 'next_cursor' => $nextCursor, 'total' => $total];
    }

    /**
     * Gender equality + every search term as a prefix of first name, last name
     * or email. Prefix LIKE ('ana%') can use B-tree indexes; the column
     * collation (utf8mb4_unicode_ci) makes it case- and accent-insensitive.
     */
    private function applyFilters(BaseBuilder $builder, UserListQuery $query): void
    {
        if ($query->gender !== null) {
            $builder->where('gender', $query->gender);
        }

        foreach ($query->terms as $rawTerm) {
            // CI 4.7 like() emits `ESCAPE '!'` but leaves % and _ in the value
            // unescaped (verified: q=% compiled to LIKE '%%'), so a user "%"
            // would match everything. Escape them with that same character.
            $esc  = $this->db->likeEscapeChar;
            $term = str_replace([$esc, '%', '_'], [$esc . $esc, $esc . '%', $esc . '_'], $rawTerm);

            $builder->groupStart()
                ->like('first_name', $term, 'after')
                ->orLike('last_name', $term, 'after')
                ->orLike('email', $term, 'after')
                ->groupEnd();
        }
    }

    /**
     * Rows strictly after the cursor in `ORDER BY sort dir, id dir`:
     * `sort > v OR (sort = v AND id > last_id)` (mirrored for desc).
     * Expanded instead of a row comparison `(sort, id) > (v, id)`, which MySQL
     * does not always turn into an index range scan.
     *
     * @param array{value: int|string, id: int} $after
     */
    private function applyKeyset(BaseBuilder $builder, string $sort, string $dir, array $after): void
    {
        $op = $dir === 'asc' ? '>' : '<';

        if ($sort === 'id') {
            $builder->where("id {$op}", $after['id']);

            return;
        }

        // $sort is whitelisted by UserListQuery::SORTABLE.
        $builder->groupStart()
            ->where("{$sort} {$op}", $after['value'])
            ->orGroupStart()
            ->where($sort, $after['value'])
            ->where("id {$op}", $after['id'])
            ->groupEnd()
            ->groupEnd();
    }

    /**
     * trim string fields; lowercase `email`; empty `telephone` → null (so
     * "no telephone" is always stored as NULL, whether omitted or "").
     */
    private function normalize(array $row): array
    {
        foreach (['first_name', 'last_name', 'email', 'telephone'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = trim($row[$field]);
            }
        }

        if (isset($row['email']) && is_string($row['email'])) {
            $row['email'] = strtolower($row['email']);
        }

        if (array_key_exists('telephone', $row) && $row['telephone'] === '') {
            $row['telephone'] = null;
        }

        return $row;
    }
}
