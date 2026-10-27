<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Gives every API failure the same body: message, stable code, and errors.
 */
final class ApiExceptionRenderer
{
    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, array<int, string>>, 4: array<string, string>}
     */
    private function fromHttpException(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();

        [$code, $defaultMessage] = match ($status) {
            401 => ['unauthenticated', 'Unauthenticated.'],
            403 => ['forbidden', 'This action is unauthorized.'],
            404 => ['not_found', 'Resource not found.'],
            405 => ['method_not_allowed', 'Method not allowed.'],
            409 => ['state_conflict', 'The request conflicts with the current state.'],
            422 => ['validation_failed', 'The given data was invalid.'],
            429 => ['too_many_requests', 'Too many requests. Please try again later.'],
            default => [
                $status >= 500 ? 'server_error' : 'http_error',
                $status >= 500 ? 'An unexpected error occurred.' : 'The request could not be processed.',
            ],
        };

        $message = $status >= 500
            ? $defaultMessage
            : ($exception->getMessage() !== '' ? $exception->getMessage() : $defaultMessage);

        return [$status, $code, $message, [], $exception->getHeaders()];
    }

    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof HttpResponseException) {
            $response = $exception->getResponse();

            if ($requestId = $request->attributes->get('request_id')) {
                $response->headers->set('X-Request-ID', (string) $requestId);
            }

            return $response;
        }

        [$status, $code, $message, $errors, $headers] = match (true) {
            $exception instanceof ValidationException => [
                422,
                'validation_failed',
                $exception->getMessage(),
                $exception->errors(),
                [],
            ],
            $exception instanceof AuthenticationException => [
                401,
                'unauthenticated',
                'Unauthenticated.',
                [],
                [],
            ],
            $exception instanceof StateConflictException => [
                409,
                'state_conflict',
                $exception->getMessage(),
                [],
                [],
            ],
            $exception instanceof DownloadApprovalRequiredException => [
                403,
                'download_approval_required',
                $exception->getMessage(),
                [],
                [],
            ],
            $exception instanceof FileMissingException => [
                500,
                'file_missing',
                $exception->getMessage(),
                [],
                [],
            ],
            $exception instanceof ThrottleRequestsException => [
                429,
                'too_many_requests',
                'Too many requests. Please try again later.',
                [],
                $exception->getHeaders(),
            ],
            $exception instanceof AccessDeniedHttpException,
            $exception instanceof AuthorizationException => [
                403,
                'forbidden',
                'This action is unauthorized.',
                [],
                [],
            ],
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => [
                404,
                'not_found',
                'Resource not found.',
                [],
                [],
            ],
            $exception instanceof MethodNotAllowedHttpException => [
                405,
                'method_not_allowed',
                'Method not allowed.',
                [],
                [],
            ],
            $exception instanceof HttpExceptionInterface => $this->fromHttpException($exception),
            default => [
                500,
                'server_error',
                'An unexpected error occurred.',
                [],
                [],
            ],
        };

        $response = response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $errors,
        ], $status, $headers);

        if ($requestId = $request->attributes->get('request_id')) {
            $response->headers->set('X-Request-ID', (string) $requestId);
        }

        return $response;
    }
}
