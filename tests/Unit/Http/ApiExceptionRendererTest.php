<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Http;

use ByfallCode\ByfallCrud\Http\ApiExceptionRenderer;
use ByfallCode\ByfallCrud\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiExceptionRendererTest extends TestCase
{
    #[DataProvider('exceptionProvider')]
    public function test_it_maps_framework_exceptions(\Throwable $exception, int $status, string $code): void
    {
        $response = (new ApiExceptionRenderer())->render(Request::create('/api/test'), $exception);
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertFalse($response->getData(true)['success']);
        self::assertSame($code, $response->getData(true)['error_code']);
    }

    public static function exceptionProvider(): iterable
    {
        yield 'unauthenticated' => [new AuthenticationException(), 401, 'UNAUTHENTICATED'];
        yield 'forbidden' => [new AuthorizationException(), 403, 'FORBIDDEN'];
        yield 'resource missing' => [(new ModelNotFoundException())->setModel('SecretModel', [99]), 404, 'RESOURCE_NOT_FOUND'];
        yield 'endpoint missing' => [new NotFoundHttpException('internal route details'), 404, 'ENDPOINT_NOT_FOUND'];
        yield 'method invalid' => [new MethodNotAllowedHttpException(['GET']), 405, 'METHOD_NOT_ALLOWED'];
        yield 'rate limited' => [new ThrottleRequestsException('internal throttle', headers: ['Retry-After' => '60', 'X-RateLimit-Limit' => '10']), 429, 'RATE_LIMIT_EXCEEDED'];
    }

    public function test_validation_errors_are_preserved(): void
    {
        $exception = ValidationException::withMessages(['email' => ['The email field is required.']]);
        $response = (new ApiExceptionRenderer())->render(Request::create('/api/users'), $exception);

        self::assertSame(422, $response?->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $response?->getData(true)['error_code']);
        self::assertSame(['The email field is required.'], $response?->getData(true)['errors']['email']);
    }

    public function test_rate_limit_headers_are_preserved(): void
    {
        $exception = new ThrottleRequestsException(headers: ['Retry-After' => '45', 'X-RateLimit-Remaining' => '0']);
        $response = (new ApiExceptionRenderer())->render(Request::create('/api/users'), $exception);

        self::assertSame('45', $response?->headers->get('Retry-After'));
        self::assertSame('0', $response?->headers->get('X-RateLimit-Remaining'));
    }

    public function test_method_not_allowed_header_is_preserved(): void
    {
        $response = (new ApiExceptionRenderer())->render(
            Request::create('/api/users'), new MethodNotAllowedHttpException(['GET', 'HEAD']),
        );

        self::assertSame(405, $response?->getStatusCode());
        self::assertSame('GET, HEAD', $response?->headers->get('Allow'));
    }

    public function test_production_server_error_does_not_leak_sensitive_details(): void
    {
        $secret = 'SQLSTATE password=super-secret at C:\\private\\Service.php:42 /home/private/project/Service.php';
        $response = (new ApiExceptionRenderer())->render(Request::create('/api/users'), new RuntimeException($secret), false);
        $json = (string) $response?->getContent();

        self::assertSame(500, $response?->getStatusCode());
        self::assertStringContainsString('Internal server error.', $json);
        self::assertStringContainsString('INTERNAL_SERVER_ERROR', $json);
        foreach (['SQLSTATE', 'super-secret', 'C:\\private', '/home/private', 'Service.php', ':42', 'RuntimeException', 'trace', 'file', 'line'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_debug_mode_exposes_only_controlled_exception_context(): void
    {
        $response = (new ApiExceptionRenderer())->render(
            Request::create('/api/users'), new RuntimeException('Debug detail'), true,
        );
        $payload = $response?->getData(true);

        self::assertSame('Debug detail', $payload['message']);
        self::assertSame(RuntimeException::class, $payload['errors']['exception']);
        self::assertArrayNotHasKey('trace', $payload['errors']);
        self::assertArrayNotHasKey('file', $payload['errors']);
        self::assertArrayNotHasKey('line', $payload['errors']);
    }

    public function test_web_exceptions_are_left_to_laravel(): void
    {
        $response = (new ApiExceptionRenderer())->render(Request::create('/web/missing'), new NotFoundHttpException());
        self::assertNull($response);
    }
}
