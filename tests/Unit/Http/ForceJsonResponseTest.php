<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Http;

use ByfallCode\ByfallCrud\Http\Middleware\ForceJsonResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ForceJsonResponseTest extends TestCase
{
    public function test_api_request_is_marked_as_expecting_json(): void
    {
        $request = Request::create('/api/users');
        self::assertFalse($request->expectsJson());

        $response = (new ForceJsonResponse())->handle($request, function (Request $request): Response {
            self::assertTrue($request->expectsJson());
            return new JsonResponse(['ok' => true]);
        });

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function test_existing_json_accept_header_remains_valid(): void
    {
        $request = Request::create('/api/users', server: ['HTTP_ACCEPT' => 'application/json']);
        (new ForceJsonResponse())->handle($request, static fn (): Response => new JsonResponse());
        self::assertSame('application/json', $request->headers->get('Accept'));
    }

    public function test_web_request_is_not_modified(): void
    {
        $request = Request::create('/web/users', server: ['HTTP_ACCEPT' => 'text/html']);
        (new ForceJsonResponse())->handle($request, static fn (): Response => new Response('web'));
        self::assertSame('text/html', $request->headers->get('Accept'));
        self::assertFalse($request->expectsJson());
    }

    public function test_non_json_response_content_type_is_not_falsified(): void
    {
        $request = Request::create('/api/plain');
        $response = (new ForceJsonResponse())->handle(
            $request,
            static fn (): Response => new Response('plain text', headers: ['Content-Type' => 'text/plain']),
        );
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('plain text', $response->getContent());
    }
}
