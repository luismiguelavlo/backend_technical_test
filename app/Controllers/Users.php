<?php

namespace App\Controllers;

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

    public function index(): ResponseInterface
    {
        return $this->respondData($this->model->findAll());
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

        return $this->respondData($user);
    }

    public function create(): ResponseInterface
    {
        $data = $this->readJsonBody();

        // The model validates before applying $allowedFields, so a body `id`
        // would reach the rules (e.g. is_unique's {id}); the PK is never client-set.
        unset($data['id']);

        $id = $this->write(fn () => $this->model->insert($data));

        if ($id === false) {
            return $this->respondValidationFailed();
        }

        return $this->respondData($this->model->find($id), 201);
    }

    /**
     * Full replacement: same required fields as create; an omitted optional
     * field is cleared. The ID always comes from the URL (the model overrides
     * any body `id` with it).
     *
     * @param int|string|null $id
     */
    public function update($id = null): ResponseInterface
    {
        if ($this->model->find($id) === null) {
            return $this->respondUserNotFound();
        }

        $data = $this->readJsonBody() + ['telephone' => null];

        // By default update() drops rules for absent fields (partial update).
        $this->model->cleanRules(false);

        $updated = $this->write(fn () => $this->model->update($id, $data));

        if ($updated === false) {
            return $this->respondValidationFailed();
        }

        return $this->respondData($this->model->find($id));
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

    private function respondValidationFailed(): ResponseInterface
    {
        return $this->respondError(422, 'Validation failed', $this->model->errors());
    }

    private function respondUserNotFound(): ResponseInterface
    {
        return $this->respondError(404, 'User not found');
    }
}
