<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Http;

use ByfallCode\ByfallCrud\Http\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationResponseTest extends TestCase
{
    #[DataProvider('pages')]
    public function test_paginated_contract(int $page, array $items, int $total, ?int $from, ?int $to, ?string $prev, ?string $next): void
    {
        $paginator = new LengthAwarePaginator($items, $total, 2, $page, ['path' => 'https://example.test/api/items']);
        $payload = ApiResponse::paginated($paginator)->getData(true);

        self::assertTrue($payload['success']);
        self::assertSame($items, $payload['data']);
        self::assertSame($page, $payload['meta']['current_page']);
        self::assertSame(2, $payload['meta']['per_page']);
        self::assertSame($total, $payload['meta']['total']);
        self::assertSame($from, $payload['meta']['from']);
        self::assertSame($to, $payload['meta']['to']);
        self::assertSame($prev, $payload['links']['prev']);
        self::assertSame($next, $payload['links']['next']);
        self::assertArrayHasKey('first', $payload['links']);
        self::assertArrayHasKey('last', $payload['links']);
    }

    public static function pages(): iterable
    {
        yield 'first page' => [1, [['id' => 1], ['id' => 2]], 5, 1, 2, null, 'https://example.test/api/items?page=2'];
        yield 'middle page' => [2, [['id' => 3], ['id' => 4]], 5, 3, 4, 'https://example.test/api/items?page=1', 'https://example.test/api/items?page=3'];
        yield 'last page' => [3, [['id' => 5]], 5, 5, 5, 'https://example.test/api/items?page=2', null];
        yield 'empty' => [1, [], 0, null, null, null, null];
    }
}
