<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function render(Request $request, Throwable $exception, bool $debug = false): ?JsonResponse
    {
        if (!$request->is('api/*')) return null;

        return match (true) {
            $exception instanceof ValidationException => ApiResponse::error(
                'Validation failed.', ApiErrorCode::Validation, Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->errors(),
            ),
            $exception instanceof AuthenticationException => ApiResponse::error(
                'Unauthenticated.', ApiErrorCode::Unauthenticated, Response::HTTP_UNAUTHORIZED,
            ),
            $exception instanceof AuthorizationException => ApiResponse::error(
                'Forbidden.', ApiErrorCode::Forbidden, Response::HTTP_FORBIDDEN,
            ),
            $exception instanceof ModelNotFoundException => ApiResponse::error(
                'Resource not found.', ApiErrorCode::ResourceNotFound, Response::HTTP_NOT_FOUND,
            ),
            $exception instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'Method not allowed.', ApiErrorCode::MethodNotAllowed, Response::HTTP_METHOD_NOT_ALLOWED,
                headers: $exception->getHeaders(),
            ),
            $exception instanceof ThrottleRequestsException || $this->isRateLimit($exception) => ApiResponse::error(
                'Too many requests.', ApiErrorCode::RateLimitExceeded, Response::HTTP_TOO_MANY_REQUESTS,
                headers: $this->headers($exception),
            ),
            $exception instanceof NotFoundHttpException => ApiResponse::error(
                'Endpoint not found.', ApiErrorCode::EndpointNotFound, Response::HTTP_NOT_FOUND,
                headers: $exception->getHeaders(),
            ),
            default => ApiResponse::error(
                $debug ? $exception->getMessage() : 'Internal server error.',
                ApiErrorCode::InternalServerError,
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $debug ? ['exception' => $exception::class] : [],
            ),
        };
    }

    private function isRateLimit(Throwable $exception): bool
    {
        return $exception instanceof HttpExceptionInterface
            && $exception->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS;
    }

    private function headers(Throwable $exception): array
    {
        return $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
    }
}
