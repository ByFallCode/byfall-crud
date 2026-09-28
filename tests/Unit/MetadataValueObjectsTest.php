<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\IndexMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;

final class MetadataValueObjectsTest extends TestCase
{
    public function test_metadata_value_objects_expose_normalized_schema_facts(): void
    {
        $foreignKey = new ForeignKeyMetadata('category_id', 'categories');
        $column = new ColumnMetadata(
            name: 'category_id',
            databaseType: 'bigint',
            normalizedType: 'integer',
            unsigned: true,
            foreignKey: $foreignKey,
        );
        $unique = new UniqueConstraintMetadata(['tenant_id', 'slug'], 'tenant_slug_unique');
        $index = new IndexMetadata(['category_id'], 'products_category_id_index');
        $entity = new EntityMetadata(
            name: 'Product',
            table: 'products',
            columns: [$column],
            indexes: [$index],
            uniqueConstraints: [$unique],
            fillable: ['category_id'],
        );

        self::assertSame('categories', $column->foreignKey?->referencedTable);
        self::assertSame(['tenant_id', 'slug'], $unique->columns);
        self::assertFalse($index->unique);
        self::assertSame('Product', $entity->name);
        self::assertSame([$column], $entity->columns);
    }
}
