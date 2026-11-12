<?php

namespace App\Exceptions;

use CodeIgniter\Exceptions\RuntimeException;
use CodeIgniter\HTTP\ResponsableInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Short-circuits an API controller action with a ready-made response.
 * Caught by BaseApiController::_remap() so route filters (CORS) still run.
 */
final class ApiResponseException extends RuntimeException implements ResponsableInterface
{
    public function __construct(private readonly ResponseInterface $response)
    {
        parent::__construct('API response short-circuit.');
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
