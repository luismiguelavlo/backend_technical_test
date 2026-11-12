<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Invalid GET /users query parameters (→ 400 with field errors).
 */
final class InvalidListQueryException extends InvalidArgumentException
{
    /**
     * @param array<string, string> $errors parameter => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid query parameters.');
    }
}
