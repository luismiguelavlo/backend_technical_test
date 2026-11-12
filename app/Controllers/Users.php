<?php

namespace App\Controllers;

use App\Libraries\InvalidListQueryException;
use App\Libraries\UserListQuery;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JSON REST API for the `users` resource.
 *
 * @property UserModel $model
 */
class Users extends BaseApiController
{
    protected $modelName = UserModel::class;

    /**
     * Search, filter, sort and keyset pagination (specs/04):
     * ?q=&gender=&sort=&dir=&limit=&cursor=&with_total=1
     */
    public function index(): ResponseInterface
    {
        try {
            $query = UserListQuery::fromArray($this->request->getGet());
        } catch (InvalidListQueryException $e) {
            return $this->respondError(400, 'Invalid query parameters', $e->errors);
        }

        $page = $this->model->listPage($query);

        return $this->respondData($page['rows'], 200, [
            'limit'       => $query->limit,
            'sort'        => $query->sort,
            'dir'         => $query->dir,
            'next_cursor' => $page['next_cursor'],
            'total'       => $page['total'],
        ]);
    }

    /**
     * @param int|string|null $id
     */
    public function show($id = null): ResponseInterface
    {
        $user = $this->model->find($id);

        if ($user === null) {
            return $this->respondUserNotFound();
        }

        return $this->respondUser($user);
    }

    public function create(): ResponseInterface
    {
        $data = $this->readUserBody();

        $id = $this->write(fn () => $this->model->insert($data));

        if ($id === false) {
            return $this->respondValidationFailed();
        }

        return $this->respondUser($this->model->find($id), 201);
    }

    /**
     * Full replacement: same required fields as create; an omitted optional
     * field is cleared. The ID always comes from the URL (the model overrides
     * any body `id` with it).
     *
     * Optimistic concurrency (specs/03): a client that sends the ETag it
     * loaded in `If-Match` gets 412 if the user changed since. Without
     * `If-Match` the update applies to the current version (last write wins),
     * keeping the 200/404/422 contract of the test spec.
     *
     * @param int|string|null $id
     */
    public function update($id = null): ResponseInterface
    {
        $current = $this->model->find($id);

        if ($current === null) {
            return $this->respondUserNotFound();
        }

        $expectedVersion = $this->readIfMatchVersion() ?? (int) $current['version'];
        $data            = $this->readUserBody() + ['telephone' => null];

        // Stale copy: reject before validating data the client built from it.
        if ($expectedVersion !== (int) $current['version']) {
            return $this->respondVersionConflict($current);
        }

        // By default update() drops rules for absent fields (partial update).
        $this->model->cleanRules(false);

        $affected = $this->write(fn () => $this->model->updateIfVersion($id, $data, $expectedVersion));

        if ($affected === false) {
            return $this->respondValidationFailed();
        }

        $user = $this->model->find($id);

        if ($user === null) {
            return $this->respondUserNotFound();
        }

        // Another request won the race between the check above and the UPDATE.
        if ($affected === 0) {
            return $this->respondVersionConflict($user);
        }

        return $this->respondUser($user);
    }

    /**
     * @param int|string|null $id
     */
    public function delete($id = null): ResponseInterface
    {
        if ($this->model->find($id) === null) {
            return $this->respondUserNotFound();
        }

        $this->write(fn () => $this->model->delete($id));

        return $this->respondNoContent();
    }

    /**
     * JSON body without server-owned fields. The model validates before
     * applying $allowedFields, so a body `id` would reach the rules (e.g.
     * is_unique's {id}); `version` only changes through updateIfVersion().
     *
     * @return array<string, mixed>
     */
    private function readUserBody(): array
    {
        $data = $this->readJsonBody();
        unset($data['id'], $data['version']);

        return $data;
    }

    /**
     * Parses `If-Match: "<version>"` (strong ETag, as sent by etagFor()).
     * Missing → null (unconditional update). A malformed value or `*` never
     * matches → -1 (412).
     */
    private function readIfMatchVersion(): ?int
    {
        $header = trim($this->request->getHeaderLine('If-Match'));

        if ($header === '') {
            return null;
        }

        return preg_match('/^"(\d{1,10})"$/', $header, $matches) === 1 ? (int) $matches[1] : -1;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function etagFor(array $user): string
    {
        return '"' . $user['version'] . '"';
    }

    /**
     * `{ data: User }` plus its ETag, so the client can send it back in If-Match.
     *
     * @param array<string, mixed> $user
     */
    private function respondUser(array $user, int $status = 200): ResponseInterface
    {
        return $this->respondData($user, $status)->setHeader('ETag', $this->etagFor($user));
    }

    /**
     * 412 with the current ETag: the client must reload before saving again.
     *
     * @param array<string, mixed> $current
     */
    private function respondVersionConflict(array $current): ResponseInterface
    {
        return $this->respondError(
            412,
            'Precondition failed',
            ['version' => 'Otro usuario modificó este registro. Recarga los datos antes de guardar.'],
        )->setHeader('ETag', $this->etagFor($current));
    }

    private function respondValidationFailed(): ResponseInterface
    {
        return $this->respondError(422, 'Validation failed', $this->model->errors());
    }

    private function respondUserNotFound(): ResponseInterface
    {
        return $this->respondError(404, 'User not found');
    }
}
