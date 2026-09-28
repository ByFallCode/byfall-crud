<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Http;

use ByfallCode\ByfallCrud\Http\ApiErrorCode;
use ByfallCode\ByfallCrud\Http\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase
{
    public function test_success_contract_supports_null_objects_arrays_and_meta(): void
    {
        $null = ApiResponse::success();
        $object = ApiResponse::success((object) ['id' => 1], 'Found.');
        $array = ApiResponse::success([['id' => 1]], meta: ['total' => 1]);

        self::assertSame(['success' => true, 'message' => 'Resource retrieved successfully.', 'data' => null], $null->getData(true));
        self::assertSame(1, $object->getData(true)['data']['id']);
        self::assertSame('Found.', $object->getData(true)['message']);
        self::assertSame(['total' => 1], $array->getData(true)['meta']);
        self::assertSame(200, $array->getStatusCode());
    }

    public function test_created_error_and_no_content_respect_http_semantics(): void
    {
        $created = ApiResponse::created(['id' => 9]);
        $error = ApiResponse::error('Forbidden.', ApiErrorCode::Forbidden, 403, ['policy' => ['denied']]);
        $empty = ApiResponse::noContent();

        self::assertSame(201, $created->getStatusCode());
        self::assertTrue($created->getData(true)['success']);
        self::assertSame(403, $error->getStatusCode());
        self::assertSame('FORBIDDEN', $error->getData(true)['error_code']);
        self::assertSame(['policy' => ['denied']], $error->getData(true)['errors']);
        self::assertSame(204, $empty->getStatusCode());
        self::assertSame('', $empty->getContent());
    }
}
