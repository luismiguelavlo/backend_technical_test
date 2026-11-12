<?php

namespace App\Libraries;

/**
 * Validated query string of GET /users (specs/04-users-list-query.md):
 * search, gender filter, sort, page size and keyset cursor.
 *
 * All request parsing lives here, so the controller stays thin and the model
 * only ever receives whitelisted columns and typed values.
 */
final class UserListQuery
{
    /** Columns the list can be sorted by (each backed by an index ending in `id`). */
    public const SORTABLE = ['id', 'first_name', 'last_name', 'email', 'age'];

    public const GENDERS       = ['male', 'female', 'other'];
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 100;
    public const MAX_TERMS     = 5;
    private const MAX_Q_LENGTH = 100;

    /**
     * @param list<string>                   $terms  search words (prefix match, AND)
     * @param array{value: int|string, id: int}|null $after last row of the previous page
     */
    private function __construct(
        public readonly array $terms,
        public readonly ?string $gender,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $limit,
        public readonly ?array $after,
        public readonly bool $withTotal,
    ) {
    }

    /**
     * @param array<string, mixed> $params $_GET
     *
     * @throws InvalidListQueryException field => message for every invalid parameter
     */
    public static function fromArray(array $params): self
    {
        $errors = [];
        $string = static function (string $key) use ($params, &$errors): ?string {
            if (! array_key_exists($key, $params) || $params[$key] === '') {
                return null;
            }
            if (! is_string($params[$key])) {
                $errors[$key] = 'Debe ser un único valor.';

                return null;
            }

            return trim($params[$key]);
        };

        $q = $string('q') ?? '';
        if (mb_strlen($q) > self::MAX_Q_LENGTH) {
            $errors['q'] = 'Máximo ' . self::MAX_Q_LENGTH . ' caracteres.';
        }
        $terms = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_TERMS);

        $gender = $string('gender');
        if ($gender !== null && ! in_array($gender, self::GENDERS, true)) {
            $errors['gender'] = 'Debe ser uno de: ' . implode(', ', self::GENDERS) . '.';
        }

        $sort = $string('sort') ?? 'id';
        if (! in_array($sort, self::SORTABLE, true)) {
            $errors['sort'] = 'Debe ser uno de: ' . implode(', ', self::SORTABLE) . '.';
        }

        $dir = strtolower($string('dir') ?? 'asc');
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $errors['dir'] = 'Debe ser asc o desc.';
        }

        $limitRaw = $string('limit');
        $limit    = self::DEFAULT_LIMIT;
        if ($limitRaw !== null) {
            if (preg_match('/^\d{1,3}$/', $limitRaw) !== 1 || (int) $limitRaw < 1 || (int) $limitRaw > self::MAX_LIMIT) {
                $errors['limit'] = 'Debe ser un entero entre 1 y ' . self::MAX_LIMIT . '.';
            } else {
                $limit = (int) $limitRaw;
            }
        }

        $withTotal = in_array($string('with_total'), ['1', 'true'], true);

        $after  = null;
        $cursor = $string('cursor');
        if ($cursor !== null && ! isset($errors['sort']) && ! isset($errors['dir'])) {
            $after = self::decodeCursor($cursor, $sort, $dir);
            if ($after === null) {
                $errors['cursor'] = 'Cursor inválido o de otro orden; vuelve a la primera página.';
            }
        }

        if ($errors !== []) {
            throw new InvalidListQueryException($errors);
        }

        return new self($terms, $gender, $sort, $dir, $limit, $after, $withTotal);
    }

    /**
     * Opaque cursor pointing after $row; bound to this sort and direction.
     *
     * @param array<string, mixed> $row
     */
    public function cursorAfter(array $row): string
    {
        $json = json_encode(['s' => $this->sort, 'd' => $this->dir, 'v' => $row[$this->sort], 'id' => $row['id']]);

        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /**
     * @return array{value: int|string, id: int}|null null if malformed or issued for another sort/dir
     */
    private static function decodeCursor(string $cursor, string $sort, string $dir): ?array
    {
        if (strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($data) || ($data['s'] ?? null) !== $sort || ($data['d'] ?? null) !== $dir) {
            return null;
        }

        $id    = $data['id'] ?? null;
        $value = $data['v'] ?? null;
        $isInt = in_array($sort, ['id', 'age'], true);

        if (! is_int($id) || $id < 1 || ($isInt ? ! is_int($value) : ! is_string($value))) {
            return null;
        }

        return ['value' => $value, 'id' => $id];
    }
}
