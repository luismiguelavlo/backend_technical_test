<?php

namespace App\Controllers;

use App\Database\DuplicateKeyTranslator;
use App\Exceptions\ApiResponseException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use ReflectionMethod;
use stdClass;

/**
 * Base for JSON API controllers: uniform success/error contract.
 *
 * Success: { "data": ... }
 * Error:   { "status": int, "message": string, "errors": {} }
 */
abstract class BaseApiController extends ResourceController
{
    protected $format = 'json';

    /**
     * Runs the routed action and turns ApiResponseException into its response,
     * so helpers like readJsonBody() can stop the action while route filters
     * (e.g. CORS) still apply.
     *
     * @param mixed ...$params
     */
    public function _remap(string $method, ...$params): ResponseInterface
    {
        if (! method_exists($this, $method) || ! (new ReflectionMethod($this, $method))->isPublic()) {
            throw PageNotFoundException::forMethodNotFound($method);
        }

        try {
            return $this->{$method}(...$params);
        } catch (ApiResponseException $e) {
            return $e->getResponse();
        }
    }

    /**
     * @param array<array-key, mixed>|null $data
     * @param array<string, mixed>|null    $meta list metadata (pagination); omitted when null
     */
    protected function respondData(?array $data, int $status = 200, ?array $meta = null): ResponseInterface
    {
        $body = ['data' => $data];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $this->respond($body, $status);
    }

    /**
     * `errors` is cast to object so an empty set serializes as `{}`, never `[]`.
     *
     * @param array<string, string> $errors field => message
     */
    protected function respondError(int $status, string $message, array $errors = []): ResponseInterface
    {
        return $this->respond([
            'status'  => $status,
            'message' => $message,
            'errors'  => (object) $errors,
        ], $status);
    }

    /**
     * Runs a DB write. A duplicate-key error on a known UNIQUE constraint
     * (model's $uniqueKeys) becomes a 422 on that field, with the model's
     * `is_unique` message; any other DatabaseException propagates (→ 500).
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     *
     * @throws ApiResponseException 422 on a known duplicate key
     */
    protected function write(callable $write): mixed
    {
        try {
            return $write();
        } catch (DatabaseException $e) {
            $field = DuplicateKeyTranslator::fieldFor($e, $this->model->uniqueKeys ?? []);

            if ($field === null) {
                throw $e;
            }

            $message = $this->model->getValidationMessages()[$field]['is_unique']
                ?? lang('Validation.is_unique', ['field' => $field]);

            throw new ApiResponseException($this->respondError(422, 'Validation failed', [$field => $message]));
        }
    }

    /**
     * Returns the request body as an associative array.
     *
     * @return array<string, mixed>
     *
     * @throws ApiResponseException 415 if Content-Type is not application/json;
     *                              400 if the body is empty, malformed or not a JSON object
     */
    protected function readJsonBody(): array
    {
        $mediaType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'), 2)[0]));

        if ($mediaType !== 'application/json') {
            throw new ApiResponseException($this->respondError(415, 'Unsupported media type'));
        }

        try {
            // Decoded as objects first: with assoc=true `{}` and `[]` are indistinguishable.
            $isObject = $this->request->getJSON() instanceof stdClass;
        } catch (HTTPException) {
            $isObject = false;
        }

        if (! $isObject) {
            throw new ApiResponseException($this->respondError(400, 'Malformed JSON body'));
        }

        return $this->request->getJSON(true);
    }
}
