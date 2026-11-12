<?php

namespace App\Exceptions;

use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Renders uncaught exceptions on API routes with the JSON error contract
 * `{ status, message, errors }`. Never exposes exception details (class,
 * message, trace, file paths, SQL) in any environment; those go to the log.
 */
final class ApiExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        if ($exception instanceof PageNotFoundException) {
            $status  = 404;
            $message = 'Not found';
        } else {
            $status  = 500;
            $message = 'Internal server error';

            log_message('error', "{class}: {message}\nin {exFile} on line {exLine}.\n{trace}", [
                'class'   => $exception::class,
                'message' => $exception->getMessage(),
                'exFile'  => clean_path($exception->getFile()),
                'exLine'  => $exception->getLine(),
                'trace'   => $exception->getTraceAsString(),
            ]);
        }

        $response
            ->setStatusCode($status)
            ->setJSON([
                'status'  => $status,
                'message' => $message,
                'errors'  => (object) [],
            ])
            ->send();

        if (ENVIRONMENT !== 'testing') {
            // @codeCoverageIgnoreStart
            exit($exitCode);
            // @codeCoverageIgnoreEnd
        }
    }
}
