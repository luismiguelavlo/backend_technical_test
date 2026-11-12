<?php

namespace Tests\Unit;

use App\Database\DuplicateKeyTranslator;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class DuplicateKeyTranslatorTest extends CIUnitTestCase
{
    private const UNIQUE_KEYS = ['uk_users_email' => 'email'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function knownKeyMessageProvider(): iterable
    {
        // MySQL 8.0.19+ (incl. 8.4) prefixes the table name.
        yield 'mysql 8.4' => ["Duplicate entry 'ana@example.com' for key 'users.uk_users_email'"];
        yield 'mysql < 8.0.19' => ["Duplicate entry 'ana@example.com' for key 'uk_users_email'"];
    }

    #[DataProvider('knownKeyMessageProvider')]
    public function testDuplicateOnKnownKeyReturnsField(string $message): void
    {
        $e = new DatabaseException($message, 1062);

        $this->assertSame('email', DuplicateKeyTranslator::fieldFor($e, self::UNIQUE_KEYS));
    }

    public function testDuplicateOnUnknownKeyReturnsNull(): void
    {
        $e = new DatabaseException("Duplicate entry '600111222' for key 'users.uk_users_telephone'", 1062);

        $this->assertNull(DuplicateKeyTranslator::fieldFor($e, self::UNIQUE_KEYS));
    }

    public function testOtherErrorCodeReturnsNull(): void
    {
        $e = new DatabaseException("Column 'email' cannot be null", 1048);

        $this->assertNull(DuplicateKeyTranslator::fieldFor($e, self::UNIQUE_KEYS));
    }

    public function testKeyNameInsideDuplicatedValueCannotSpoofConstraint(): void
    {
        $e = new DatabaseException(
            "Duplicate entry 'x' for key 'users.uk_users_email' for key 'users.uk_users_telephone'",
            1062,
        );

        $this->assertNull(DuplicateKeyTranslator::fieldFor($e, self::UNIQUE_KEYS));
    }
}
