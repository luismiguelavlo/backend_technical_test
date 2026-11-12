<?php

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Exceptions\RuntimeException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Cors;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Throwable;

/**
 * @internal
 */
final class UsersApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        // Don't depend on the developer's .env: every test starts with the
        // .env.example value.
        $this->withCorsOrigins(self::FRONTEND_ORIGIN);
    }

    protected function tearDown(): void
    {
        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        Factories::reset('models');
        Factories::reset('config');

        parent::tearDown();
    }

    /**
     * Sets CORS_ALLOWED_ORIGINS and rebuilds Config\Cors from it.
     */
    private function withCorsOrigins(string $origins): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS']    = $origins;
        $_SERVER['CORS_ALLOWED_ORIGINS'] = $origins;

        Factories::injectMock('config', 'Cors', new Cors());
    }

    /**
     * @param array<string, mixed> $overrides
     *
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createUser(array $overrides = []): int
    {
        $id = model(UserModel::class)->insert($this->validUser($overrides));
        $this->assertIsInt($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $result): array
    {
        return json_decode($result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertErrorContract(TestResponse $result, int $status, string $message): void
    {
        $result->assertStatus($status);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $body = $this->json($result);
        $this->assertSame($status, $body['status']);
        $this->assertSame($message, $body['message']);
        $this->assertArrayHasKey('errors', $body);
    }

    /**
     * `errors` must serialize as `{}` (object), never `[]`.
     */
    private function assertEmptyErrorsObject(string $json): void
    {
        $body = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $body->errors);
        $this->assertSame([], get_object_vars($body->errors));
    }

    public function testIndexReturnsAllUsersInDataEnvelope(): void
    {
        $this->createUser();
        $this->createUser(['email' => 'otro@example.com']);

        $result = $this->get('users');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $body = $this->json($result);
        $this->assertSame(['data'], array_keys($body));
        $this->assertCount(2, $body['data']);
    }

    public function testIndexWithNoUsersReturnsEmptyList(): void
    {
        $result = $this->get('users');

        $result->assertStatus(200);
        $this->assertSame(['data' => []], $this->json($result));
    }

    public function testShowReturnsUserWithAllFields(): void
    {
        $id = $this->createUser();

        $result = $this->get("users/{$id}");

        $result->assertStatus(200);
        $data = $this->json($result)['data'];
        $this->assertSame(
            ['id', 'first_name', 'last_name', 'email', 'gender', 'telephone', 'age', 'version', 'created_at', 'updated_at'],
            array_keys($data),
        );
        $this->assertSame('ana.garcia@example.com', $data['email']);
    }

    public function testShowUnknownIdReturns404WithEmptyErrorsObject(): void
    {
        $result = $this->get('users/999999');

        $this->assertErrorContract($result, 404, 'User not found');
        $this->assertEmptyErrorsObject($result->getJSON());
    }

    public function testShowIdZeroReturns404(): void
    {
        $result = $this->get('users/0');

        $this->assertErrorContract($result, 404, 'User not found');
        $this->assertEmptyErrorsObject($result->getJSON());
    }

    /**
     * Feature requests don't go through PHP's global exception handler, so
     * uncaught exceptions are fed to the real one (service('exceptions')),
     * which picks the handler via Config\Exceptions::handler().
     *
     * @return array{status: int, contentType: string, body: string}
     */
    private function callThroughExceptionHandler(string $method, string $path): array
    {
        try {
            $this->call($method, $path);
        } catch (Throwable $e) {
            ob_start();
            service('exceptions', null, false)->exceptionHandler($e);
            $body = (string) ob_get_clean();

            $response = service('response');

            return [
                'status'      => $response->getStatusCode(),
                'contentType' => $response->getHeaderLine('Content-Type'),
                'body'        => $body,
            ];
        }

        $this->fail("Expected an uncaught exception for {$method} {$path}.");
    }

    /**
     * @param array{status: int, contentType: string, body: string} $result
     */
    private function assertApiErrorBody(array $result, int $status, string $message): void
    {
        $this->assertSame($status, $result['status']);
        $this->assertStringStartsWith('application/json', $result['contentType']);

        $body = json_decode($result['body'], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['status', 'message', 'errors'], array_keys(get_object_vars($body)));
        $this->assertSame($status, $body->status);
        $this->assertSame($message, $body->message);
        $this->assertEmptyErrorsObject($result['body']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unmatchedApiPathProvider(): iterable
    {
        yield 'non-numeric id' => ['users/abc'];
        yield 'negative id' => ['users/-1'];
        yield 'nested unknown route' => ['users/1/foo'];
    }

    #[DataProvider('unmatchedApiPathProvider')]
    public function testUnmatchedApiRouteReturnsJson404(string $path): void
    {
        $result = $this->callThroughExceptionHandler('GET', $path);

        $this->assertApiErrorBody($result, 404, 'Not found');
    }

    public function testUnexpectedExceptionReturnsGeneric500WithoutDetails(): void
    {
        $secret = 'SQLSTATE[HY000] SELECT * FROM users in C:\secret\path\File.php';

        $model = $this->createMock(UserModel::class);
        $model->method('findAll')->willThrowException(new RuntimeException($secret));
        Factories::injectMock('models', UserModel::class, $model);

        $result = $this->callThroughExceptionHandler('GET', 'users');

        $this->assertApiErrorBody($result, 500, 'Internal server error');

        foreach (['SQLSTATE', 'SELECT', 'secret', 'RuntimeException', '.php', '#0'] as $leak) {
            $this->assertStringNotContainsString($leak, $result['body']);
        }

        $this->assertTrue(
            TestLogger::didLog('error', $secret, false),
            'Exception detail must be logged at "error" level.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonApiPathProvider(): iterable
    {
        yield 'unknown page' => ['no-existe'];
        yield 'prefix lookalike' => ['usersfoo'];
    }

    #[DataProvider('nonApiPathProvider')]
    public function testNonApiRouteUsesDefaultHandler(string $path): void
    {
        $result = $this->callThroughExceptionHandler('GET', $path);

        $this->assertSame(404, $result['status']);

        $decoded = json_decode($result['body'], true);
        $this->assertFalse(
            is_array($decoded) && array_key_exists('errors', $decoded),
            'Non-API routes must not use the API error contract.',
        );
    }

    /**
     * @param array<array-key, mixed>|string $body    array → JSON-encoded; string → sent raw
     * @param array<string, string>          $headers extra request headers
     */
    private function sendJson(
        string $method,
        string $path,
        array|string $body,
        string $contentType = 'application/json',
        array $headers = [],
    ): TestResponse {
        return $this
            ->withHeaders(['Content-Type' => $contentType] + $headers)
            ->withBody(is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body)
            ->call($method, $path);
    }

    /**
     * PUT /users/{id} with `If-Match` (default: the ETag of a freshly created
     * user, version 1). Pass null to omit the header.
     *
     * @param array<array-key, mixed>|string $body
     */
    private function putUser(
        int|string $id,
        array|string $body,
        ?string $ifMatch = '"1"',
        string $contentType = 'application/json',
    ): TestResponse {
        $headers = $ifMatch === null ? [] : ['If-Match' => $ifMatch];

        return $this->sendJson('PUT', "users/{$id}", $body, $contentType, $headers);
    }

    /**
     * @param array<string, string> $expectedErrors field => exact message ('' = any message)
     */
    private function assertValidationFailed(TestResponse $result, array $expectedErrors): void
    {
        $this->assertErrorContract($result, 422, 'Validation failed');

        $errors = $this->json($result)['errors'];

        foreach ($expectedErrors as $field => $message) {
            $this->assertArrayHasKey($field, $errors);

            if ($message !== '') {
                $this->assertSame($message, $errors[$field]);
            }
        }
    }

    public function testCreateReturns201WithNormalizedUser(): void
    {
        $result = $this->sendJson('POST', 'users', $this->validUser(['email' => '  Nuevo@Example.COM ']));

        $result->assertStatus(201);
        $data = $this->json($result)['data'];
        $this->assertIsInt($data['id']);
        $this->assertGreaterThan(0, $data['id']);
        $this->assertSame('nuevo@example.com', $data['email']);
        $this->assertSame('Ana', $data['first_name']);
        $this->assertSame(30, $data['age']);
        $this->seeInDatabase('users', ['id' => $data['id'], 'email' => 'nuevo@example.com']);
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
    public function testCreateWithoutRequiredFieldReturns422(string $field): void
    {
        $data = $this->validUser();
        unset($data[$field]);

        $result = $this->sendJson('POST', 'users', $data);

        $this->assertValidationFailed($result, [$field => '']);
        $this->seeNumRecords(0, 'users', []);
    }

    public function testCreateWithInvalidEmailReturns422(): void
    {
        $result = $this->sendJson('POST', 'users', $this->validUser(['email' => 'no-es-un-email']));

        $this->assertValidationFailed($result, ['email' => 'El email no tiene un formato válido.']);
    }

    public function testCreateWithExistingEmailReturns422(): void
    {
        $this->createUser(['email' => 'ana.garcia@example.com']);

        $result = $this->sendJson('POST', 'users', $this->validUser(['email' => '  ANA.Garcia@example.com ']));

        $this->assertValidationFailed($result, ['email' => 'El email ya está registrado.']);
        $this->seeNumRecords(1, 'users', []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badJsonBodyProvider(): iterable
    {
        yield 'malformed' => ['{"first_name": "Ana",'];
        yield 'empty' => [''];
        yield 'empty array' => ['[]'];
        yield 'list of objects' => ['[{"first_name": "Ana"}]'];
        yield 'scalar' => ['"Ana"'];
        yield 'null' => ['null'];
    }

    #[DataProvider('badJsonBodyProvider')]
    public function testCreateWithBadJsonBodyReturns400(string $body): void
    {
        $result = $this->sendJson('POST', 'users', $body);

        $this->assertErrorContract($result, 400, 'Malformed JSON body');
        $this->assertEmptyErrorsObject($result->getJSON());
        $this->seeNumRecords(0, 'users', []);
    }

    public function testCreateWithFormUrlencodedReturns415(): void
    {
        $result = $this->sendJson(
            'POST',
            'users',
            http_build_query($this->validUser()),
            'application/x-www-form-urlencoded',
        );

        $this->assertErrorContract($result, 415, 'Unsupported media type');
        $this->assertEmptyErrorsObject($result->getJSON());
        $this->seeNumRecords(0, 'users', []);
    }

    public function testCreateAcceptsJsonContentTypeWithCharset(): void
    {
        $result = $this->sendJson('POST', 'users', $this->validUser(), 'application/json; charset=UTF-8');

        $result->assertStatus(201);
    }

    public function testCreateIgnoresIdAndUnknownFieldsInBody(): void
    {
        $existingId = $this->createUser(['email' => 'existente@example.com']);

        $result = $this->sendJson('POST', 'users', $this->validUser([
            'id'         => $existingId,
            'is_admin'   => true,
            'created_at' => '2000-01-01 00:00:00',
        ]));

        $result->assertStatus(201);
        $data = $this->json($result)['data'];
        $this->assertNotSame($existingId, $data['id']);
        $this->assertArrayNotHasKey('is_admin', $data);
        $this->assertNotSame('2000-01-01 00:00:00', $data['created_at']);
        $this->seeInDatabase('users', ['id' => $existingId, 'email' => 'existente@example.com']);
        $this->seeNumRecords(2, 'users', []);
    }

    /**
     * Simulates a race: validation (is_unique) is skipped, so the DB UNIQUE
     * constraint is what rejects the duplicate (MySQL 1062).
     */
    public function testCreateDuplicateRejectedByDbConstraintReturns422WithoutSql(): void
    {
        $this->createUser(['email' => 'ana.garcia@example.com']);

        $model = new UserModel();
        $model->skipValidation(true);
        Factories::injectMock('models', UserModel::class, $model);

        $result = $this->sendJson('POST', 'users', $this->validUser());

        $this->assertValidationFailed($result, ['email' => 'El email ya está registrado.']);

        $body = $result->getJSON();

        foreach (['Duplicate entry', 'uk_users_email', 'INSERT', '1062'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }

        $this->seeNumRecords(1, 'users', []);
    }

    public function testUpdateReplacesUserAndReturns200(): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser([
            'first_name' => 'Ana María',
            'email'      => '  Ana.Nueva@Example.com ',
            'age'        => 31,
        ]));

        $result->assertStatus(200);
        $data = $this->json($result)['data'];
        $this->assertSame($id, $data['id']);
        $this->assertSame('Ana María', $data['first_name']);
        $this->assertSame('ana.nueva@example.com', $data['email']);
        $this->assertSame(31, $data['age']);
        $this->assertNotEmpty($data['updated_at']);
        $this->assertGreaterThanOrEqual($data['created_at'], $data['updated_at']);
        $this->seeInDatabase('users', ['id' => $id, 'first_name' => 'Ana María']);
    }

    public function testUpdateUnknownIdReturns404(): void
    {
        $result = $this->putUser(999999, $this->validUser());

        $this->assertErrorContract($result, 404, 'User not found');
        $this->seeNumRecords(0, 'users', []);
    }

    #[DataProvider('requiredFieldProvider')]
    public function testUpdateWithoutRequiredFieldReturns422(string $field): void
    {
        $id   = $this->createUser();
        $data = $this->validUser(['first_name' => 'Cambiado']);
        unset($data[$field]);

        $result = $this->putUser($id, $data);

        $this->assertValidationFailed($result, [$field => '']);
        $this->seeInDatabase('users', ['id' => $id, 'first_name' => 'Ana']);
    }

    public function testUpdateWithAnotherUsersEmailReturns422(): void
    {
        $this->createUser(['email' => 'otro@example.com']);
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser(['email' => 'OTRO@example.com']));

        $this->assertValidationFailed($result, ['email' => 'El email ya está registrado.']);
        $this->seeInDatabase('users', ['id' => $id, 'email' => 'ana.garcia@example.com']);
    }

    public function testUpdateKeepingOwnEmailReturns200(): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser(['last_name' => 'López']));

        $result->assertStatus(200);
        $this->assertSame('López', $this->json($result)['data']['last_name']);
    }

    public function testUpdateWithoutTelephoneClearsIt(): void
    {
        $id   = $this->createUser();
        $data = $this->validUser();
        unset($data['telephone']);

        $result = $this->putUser($id, $data);

        $result->assertStatus(200);
        $this->assertNull($this->json($result)['data']['telephone']);
    }

    public function testUpdateIgnoresBodyIdAndUpdatesUrlUser(): void
    {
        $otherId = $this->createUser(['email' => 'otro@example.com', 'first_name' => 'Otro']);
        $id      = $this->createUser();

        $result = $this->putUser($id, $this->validUser([
            'id'         => $otherId,
            'first_name' => 'Cambiado',
        ]));

        $result->assertStatus(200);
        $this->assertSame($id, $this->json($result)['data']['id']);
        $this->seeInDatabase('users', ['id' => $id, 'first_name' => 'Cambiado']);
        $this->seeInDatabase('users', ['id' => $otherId, 'first_name' => 'Otro', 'email' => 'otro@example.com']);
    }

    public function testUpdateWithFormUrlencodedReturns415(): void
    {
        $id = $this->createUser();

        $result = $this->putUser(
            $id,
            http_build_query($this->validUser()),
            contentType: 'application/x-www-form-urlencoded',
        );

        $this->assertErrorContract($result, 415, 'Unsupported media type');
    }

    #[DataProvider('badJsonBodyProvider')]
    public function testUpdateWithBadJsonBodyReturns400(string $body): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $body);

        $this->assertErrorContract($result, 400, 'Malformed JSON body');
        $this->seeInDatabase('users', ['id' => $id, 'first_name' => 'Ana']);
    }

    // --- Optimistic concurrency (specs/03) -----------------------------------

    public function testShowAndCreateReturnVersionAndMatchingEtag(): void
    {
        $created = $this->sendJson('POST', 'users', $this->validUser());

        $created->assertStatus(201);
        $created->assertHeader('ETag', '"1"');
        $this->assertSame(1, $this->json($created)['data']['version']);

        $id = $this->json($created)['data']['id'];
        $this->get("users/{$id}")->assertHeader('ETag', '"1"');
    }

    public function testCreateIgnoresVersionInBody(): void
    {
        $result = $this->sendJson('POST', 'users', $this->validUser(['version' => 99]));

        $result->assertStatus(201);
        $this->assertSame(1, $this->json($result)['data']['version']);
    }

    public function testUpdateIncrementsVersionAndReturnsNewEtag(): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser(['age' => 40]));

        $result->assertStatus(200);
        $result->assertHeader('ETag', '"2"');
        $this->assertSame(2, $this->json($result)['data']['version']);
        $this->seeInDatabase('users', ['id' => $id, 'age' => 40, 'version' => 2]);
    }

    /**
     * Agent A and agent B load version 1; A saves first, B's save must not
     * silently overwrite A's change.
     */
    public function testSecondUpdateWithStaleVersionReturns412AndKeepsFirstChange(): void
    {
        $id = $this->createUser();

        $this->putUser($id, $this->validUser(['telephone' => '+34 699 000 000']))->assertStatus(200);

        $result = $this->putUser($id, $this->validUser(['age' => 45]));

        $this->assertErrorContract($result, 412, 'Precondition failed');
        $this->assertArrayHasKey('version', $this->json($result)['errors']);
        $result->assertHeader('ETag', '"2"');
        $this->seeInDatabase('users', ['id' => $id, 'telephone' => '+34 699 000 000', 'age' => 30, 'version' => 2]);
    }

    public function testStaleUpdateReturns412EvenWithInvalidData(): void
    {
        $id = $this->createUser();
        model(UserModel::class)->updateIfVersion($id, $this->validUser(['age' => 50]), 1);

        $result = $this->putUser($id, $this->validUser(['email' => 'no-es-email']));

        $this->assertErrorContract($result, 412, 'Precondition failed');
    }

    public function testUpdateWithoutIfMatchReturns428(): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser(['age' => 45]), ifMatch: null);

        $this->assertErrorContract($result, 428, 'Precondition required');
        $this->assertArrayHasKey('If-Match', $this->json($result)['errors']);
        $this->seeInDatabase('users', ['id' => $id, 'age' => 30, 'version' => 1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMatchingIfMatchProvider(): iterable
    {
        yield 'unquoted' => ['1'];
        yield 'weak' => ['W/"1"'];
        yield 'wildcard' => ['*'];
        yield 'garbage' => ['"abc"'];
        yield 'future version' => ['"2"'];
    }

    #[DataProvider('nonMatchingIfMatchProvider')]
    public function testUpdateWithNonMatchingIfMatchReturns412(string $ifMatch): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser(['age' => 45]), $ifMatch);

        $this->assertErrorContract($result, 412, 'Precondition failed');
        $this->seeInDatabase('users', ['id' => $id, 'age' => 30, 'version' => 1]);
    }

    public function testUpdateIgnoresVersionInBody(): void
    {
        $id = $this->createUser();

        $result = $this->putUser($id, $this->validUser(['version' => 99]));

        $result->assertStatus(200);
        $this->assertSame(2, $this->json($result)['data']['version']);
    }

    public function testValidationErrorDoesNotBumpVersion(): void
    {
        $id = $this->createUser();

        $this->assertValidationFailed($this->putUser($id, $this->validUser(['email' => 'no-es-email'])), ['email' => '']);
        $this->seeInDatabase('users', ['id' => $id, 'version' => 1]);

        // The pending `WHERE version` must not leak into the next query.
        $this->putUser($id, $this->validUser(['age' => 33]))->assertStatus(200);
    }

    public function testModelUpdateIfVersionReturnsZeroWhenVersionChanged(): void
    {
        $id    = $this->createUser();
        $model = model(UserModel::class);

        $this->assertSame(1, $model->updateIfVersion($id, $this->validUser(['age' => 31]), 1));
        $this->assertSame(0, $model->updateIfVersion($id, $this->validUser(['age' => 32]), 1));
        $this->seeInDatabase('users', ['id' => $id, 'age' => 31, 'version' => 2]);
    }

    public function testEtagIsExposedToCrossOriginScripts(): void
    {
        $id = $this->createUser();

        $result = $this->getFromOrigin("users/{$id}", self::FRONTEND_ORIGIN);

        $this->assertStringContainsStringIgnoringCase(
            'ETag',
            $result->response()->getHeaderLine('Access-Control-Expose-Headers'),
        );
    }

    public function testDeleteReturns204WithEmptyBodyAndRemovesUser(): void
    {
        $otherId = $this->createUser(['email' => 'otro@example.com']);
        $id      = $this->createUser();

        $result = $this->delete("users/{$id}");

        $result->assertStatus(204);
        $this->assertSame('', (string) $result->response()->getBody());
        $this->dontSeeInDatabase('users', ['id' => $id]);
        $this->seeInDatabase('users', ['id' => $otherId]);
    }

    public function testShowAfterDeleteReturns404(): void
    {
        $id = $this->createUser();
        $this->delete("users/{$id}")->assertStatus(204);

        $result = $this->get("users/{$id}");

        $this->assertErrorContract($result, 404, 'User not found');
    }

    public function testDeleteUnknownIdReturns404(): void
    {
        $this->createUser();

        $result = $this->delete('users/999999');

        $this->assertErrorContract($result, 404, 'User not found');
        $this->assertEmptyErrorsObject($result->getJSON());
        $this->seeNumRecords(1, 'users', []);
    }

    /**
     * The shared `response` service keeps headers between requests in the
     * same process (unlike real HTTP), which would mask missing CORS headers.
     */
    private function freshResponse(): void
    {
        Services::resetSingle('response');
    }

    private function preflight(string $path, string $origin, string $method = 'POST'): TestResponse
    {
        $this->freshResponse();

        return $this
            ->withHeaders([
                'Origin'                         => $origin,
                'Access-Control-Request-Method'  => $method,
                'Access-Control-Request-Headers' => 'content-type',
            ])
            ->call('OPTIONS', $path);
    }

    private function getFromOrigin(string $path, string $origin): TestResponse
    {
        $this->freshResponse();

        return $this->withHeaders(['Origin' => $origin])->get($path);
    }

    private function assertNoCorsAllowOrigin(TestResponse $result): void
    {
        $this->assertFalse(
            $result->response()->hasHeader('Access-Control-Allow-Origin'),
            'Unexpected Access-Control-Allow-Origin: ' . $result->response()->getHeaderLine('Access-Control-Allow-Origin'),
        );
    }

    private function assertNoUnsafeCorsHeaders(TestResponse $result): void
    {
        $this->assertNotSame('*', $result->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertNotSame('true', $result->response()->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corsPathProvider(): iterable
    {
        yield 'collection' => ['users'];
        yield 'item' => ['users/1'];
    }

    #[DataProvider('corsPathProvider')]
    public function testPreflightFromAllowedOriginReturns204WithCorsHeaders(string $path): void
    {
        $result = $this->preflight($path, self::FRONTEND_ORIGIN);

        $result->assertStatus(204);
        $result->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN);
        $result->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $result->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, If-Match');
        $this->assertNoUnsafeCorsHeaders($result);
    }

    public function testPreflightFromDisallowedOriginHasNoAllowOrigin(): void
    {
        $result = $this->preflight('users', 'https://evil.example.com');

        $this->assertNoCorsAllowOrigin($result);
    }

    public function testSimpleGetFromAllowedOriginIncludesAllowOrigin(): void
    {
        $result = $this->getFromOrigin('users', self::FRONTEND_ORIGIN);

        $result->assertStatus(200);
        $result->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN);
        $this->assertNoUnsafeCorsHeaders($result);
    }

    public function testSimpleGetFromDisallowedOriginHasNoAllowOrigin(): void
    {
        $result = $this->getFromOrigin('users', 'https://evil.example.com');

        $result->assertStatus(200);
        $this->assertNoCorsAllowOrigin($result);
    }

    public function testShortCircuitedErrorFromAllowedOriginKeepsCorsHeaders(): void
    {
        $this->freshResponse();

        $result = $this
            ->withHeaders(['Origin' => self::FRONTEND_ORIGIN, 'Content-Type' => 'text/plain'])
            ->withBody('x')
            ->post('users');

        $result->assertStatus(415);
        $result->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN);
    }

    public function testTwoCommaSeparatedOriginsAreBothAllowed(): void
    {
        $this->withCorsOrigins(' http://localhost:5173 , https://app.example.com ');

        foreach (['http://localhost:5173', 'https://app.example.com'] as $origin) {
            $this->getFromOrigin('users', $origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        $this->assertNoCorsAllowOrigin($this->getFromOrigin('users', 'https://other.example.com'));
    }

    public function testEmptyAllowedOriginsAllowsNoOrigin(): void
    {
        $this->withCorsOrigins('');

        $this->assertNoCorsAllowOrigin($this->getFromOrigin('users', self::FRONTEND_ORIGIN));
        $this->assertNoCorsAllowOrigin($this->preflight('users', self::FRONTEND_ORIGIN));
    }

    public function testWildcardOriginIsNeverAllowed(): void
    {
        $this->withCorsOrigins('*');

        $result = $this->getFromOrigin('users', 'https://evil.example.com');

        $this->assertNoCorsAllowOrigin($result);
        $cors = config('Cors');
        $this->assertSame([], $cors->default['allowedOrigins']);
        $this->assertSame([], $cors->default['allowedOriginsPatterns']);
    }
}
