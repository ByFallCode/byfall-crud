<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Inference;

use ByfallCode\ByfallCrud\Inference\QueryCapabilityInferrer;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\RelationshipMetadata;
use PHPUnit\Framework\TestCase;

final class QueryCapabilityInferrerTest extends TestCase
{
    public function test_query_capabilities_are_conservative_and_exclude_secrets(): void
    {
        $metadata = new EntityMetadata('Product', 'products', columns: [
            new ColumnMetadata('id', normalizedType: 'integer', primary: true),
            new ColumnMetadata('name', normalizedType: 'string'),
            new ColumnMetadata('description', normalizedType: 'text'),
            new ColumnMetadata('password', normalizedType: 'string'),
            new ColumnMetadata('api_token', normalizedType: 'string'),
            new ColumnMetadata('refresh_token', normalizedType: 'string'),
            new ColumnMetadata('remember_token', normalizedType: 'string'),
            new ColumnMetadata('secret', normalizedType: 'string'),
            new ColumnMetadata('category_id', normalizedType: 'integer', foreignKey: new ForeignKeyMetadata('category_id', 'categories')),
            new ColumnMetadata('active', normalizedType: 'boolean'),
            new ColumnMetadata('price', normalizedType: 'decimal'),
            new ColumnMetadata('payload', normalizedType: 'array'),
            new ColumnMetadata('created_at', normalizedType: 'datetime'),
            new ColumnMetadata('computed', normalizedType: 'string', generated: true),
        ]);
        $relations = [new RelationshipMetadata('category', 'belongsTo', 'Category', 'category_id')];
        $result = (new QueryCapabilityInferrer())->infer($metadata, $relations);

        self::assertSame(['name', 'description'], $result['searchable']);
        self::assertSame(['id', 'category_id', 'active', 'created_at'], $result['filterable']);
        self::assertSame(['id', 'name', 'category_id', 'price', 'created_at'], $result['sortable']);
        self::assertSame(['category'], $result['allowedIncludes']);
        self::assertNotContains('password', $result['searchable']);
        self::assertNotContains('api_token', $result['searchable']);
        self::assertNotContains('refresh_token', $result['searchable']);
        self::assertNotContains('remember_token', $result['searchable']);
        self::assertNotContains('secret', $result['searchable']);
    }
}
