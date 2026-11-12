<?php

namespace App\Database;

use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * The only place that knows about MySQL's duplicate-key error (1062).
 * Maps the violated UNIQUE constraint, by name, to the field it protects.
 */
final class DuplicateKeyTranslator
{
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    /**
     * MySQL: "Duplicate entry '<value>' for key '<table>.<constraint>'"
     * (the "<table>." part is absent before MySQL 8.0.19). Anchored at the
     * end so a crafted value containing "for key '...'" cannot spoof it.
     */
    private const CONSTRAINT_PATTERN = "/ for key '(?:[^']*\\.)?([^'.]+)'\\z/";

    /**
     * @param array<string, string> $uniqueKeys constraint => field
     *
     * @return string|null The field, or null if not a 1062 on a known constraint
     */
    public static function fieldFor(DatabaseException $e, array $uniqueKeys): ?string
    {
        if ($e->getCode() !== self::MYSQL_DUPLICATE_ENTRY) {
            return null;
        }

        if (preg_match(self::CONSTRAINT_PATTERN, $e->getMessage(), $matches) !== 1) {
            return null;
        }

        return $uniqueKeys[$matches[1]] ?? null;
    }
}
