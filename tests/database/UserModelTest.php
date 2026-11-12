<?php

namespace Tests\Database;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class UserModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private UserModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new UserModel();
    }

    /**
     * @return array<string, mixed>
     */
    private function validUser(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name'  => 'García',
            'email'      => 'ana.garcia@example.com',
            'gender'     => 'female',
            'telephone'  => '+34 600 111 222',
            'age'        => 30,
        ], $overrides);
    }

    public function testInsertValidUserReturnsIdAndSetsTimestamps(): void
    {
        $id = $this->model->insert($this->validUser());

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $row = $this->model->find($id);
        $this->assertSame('ana.garcia@example.com', $row['email']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertNotEmpty($row['updated_at']);
    }

    public function testInsertDuplicateEmailIsRejected(): void
    {
        $this->assertNotFalse($this->model->insert($this->validUser()));

        $result = $this->model->insert($this->validUser(['first_name' => 'Otra']));

        $this->assertFalse($result);
        $this->assertArrayHasKey('email', $this->model->errors());
        $this->seeNumRecords(1, 'users', []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFieldProvider(): iterable
    {
        foreach (['first_name', 'last_name', 'email', 'gender', 'age'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('requiredFieldProvider')]
    public function testInsertWithoutRequiredFieldIsRejected(string $field): void
    {
        $data = $this->validUser();
        unset($data[$field]);

        $this->assertFalse($this->model->insert($data));
        $this->assertArrayHasKey($field, $this->model->errors());
        $this->seeNumRecords(0, 'users', []);
    }

    public function testInsertWithoutTelephoneStoresNull(): void
    {
        $data = $this->validUser();
        unset($data['telephone']);

        $id = $this->model->insert($data);

        $this->assertNotFalse($id);
        $this->assertNull($this->model->find($id)['telephone']);
    }

    public function testInsertWithNullTelephoneStoresNull(): void
    {
        $id = $this->model->insert($this->validUser(['telephone' => null]));

        $this->assertNotFalse($id);
        $this->assertNull($this->model->find($id)['telephone']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidAgeProvider(): iterable
    {
        yield 'age 0' => [0];
        yield 'age 121' => [121];
    }

    #[DataProvider('invalidAgeProvider')]
    public function testInsertWithAgeOutOfRangeIsRejected(int $age): void
    {
        $this->assertFalse($this->model->insert($this->validUser(['age' => $age])));
        $this->assertArrayHasKey('age', $this->model->errors());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function boundaryAgeProvider(): iterable
    {
        yield 'age 1' => [1];
        yield 'age 120' => [120];
    }

    #[DataProvider('boundaryAgeProvider')]
    public function testInsertWithBoundaryAgeIsAccepted(int $age): void
    {
        $id = $this->model->insert($this->validUser(['age' => $age]));

        $this->assertNotFalse($id);
        $this->assertSame($age, (int) $this->model->find($id)['age']);
    }

    public function testInsertNormalizesEmail(): void
    {
        $id = $this->model->insert($this->validUser(['email' => '  Foo@Example.COM ']));

        $this->assertNotFalse($id);
        $this->assertSame('foo@example.com', $this->model->find($id)['email']);
    }

    public function testInsertEmailDifferingOnlyInCaseOrSpacesIsRejected(): void
    {
        $this->assertNotFalse($this->model->insert($this->validUser(['email' => 'foo@example.com'])));

        $result = $this->model->insert($this->validUser(['email' => '  FOO@example.Com ']));

        $this->assertFalse($result);
        $this->assertArrayHasKey('email', $this->model->errors());
        $this->seeNumRecords(1, 'users', []);
    }

    public function testUpdateWithOwnEmailSucceeds(): void
    {
        $id = $this->model->insert($this->validUser());

        $result = $this->model->update($id, ['email' => 'ana.garcia@example.com']);

        $this->assertTrue($result);
        $this->assertSame([], $this->model->errors());
    }
}
