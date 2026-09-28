<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Resource retrieved successfully.',
        array $meta = [],
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];
        if ($meta !== []) $payload['meta'] = $meta;
        return new JsonResponse($payload, $status);
    }

    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully.',
    ): JsonResponse {
        return self::success($data, $message, status: Response::HTTP_CREATED);
    }

    public static function noContent(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    public static function error(
        string $message,
        ApiErrorCode $errorCode,
        int $status,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode->value,
        ];
        if ($errors !== []) $payload['errors'] = $errors;
        return new JsonResponse($payload, $status, $headers);
    }

    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Resources retrieved successfully.',
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
