<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Inference;

use ByfallCode\ByfallCrud\Inference\MetadataEnricher;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;
use PHPUnit\Framework\TestCase;

final class MetadataEnricherIntegrationTest extends TestCase
{
    public function test_it_enriches_a_realistic_user_without_mutating_raw_metadata(): void
    {
        $raw = new EntityMetadata('User', 'users', columns: [
            new ColumnMetadata('id', 'bigint', 'integer', autoIncrement: true, primary: true),
            new ColumnMetadata('name', 'varchar', 'string', length: 100),
            new ColumnMetadata('email', 'varchar', 'string', length: 255, unique: true),
            new ColumnMetadata('password', 'varchar', 'string', length: 255),
            new ColumnMetadata('remember_token', 'varchar', 'string', nullable: true),
            new ColumnMetadata('active', 'boolean', 'boolean', defaultValue: true, hasDefault: true),
            new ColumnMetadata('created_at', 'timestamp', 'datetime', nullable: true),
            new ColumnMetadata('updated_at', 'timestamp', 'datetime', nullable: true),
        ], uniqueConstraints: [new UniqueConstraintMetadata(['email'])], timestamps: true);

        $enriched = (new MetadataEnricher())->enrich($raw);
        self::assertSame([], $raw->fillable);
        self::assertSame(['name', 'email', 'password', 'remember_token', 'active'], $enriched->fillable);
        self::assertSame(['password', 'remember_token'], $enriched->hidden);
        self::assertSame('sometimes|boolean', $enriched->storeRules['active']);
        self::assertStringContainsString('unique:users,email', $enriched->storeRules['email']);
        self::assertSame(['name', 'email'], $enriched->searchable);
        self::assertContains('id', $enriched->sortable);
    }

    public function test_it_enriches_a_relational_product_end_to_end(): void
    {
        $raw = new EntityMetadata('Product', 'products', columns: [
            new ColumnMetadata('id', 'bigint', 'integer', autoIncrement: true, primary: true),
            new ColumnMetadata('category_id', 'bigint', 'integer', foreignKey: new ForeignKeyMetadata('category_id', 'categories')),
            new ColumnMetadata('name', 'varchar', 'string', length: 150),
            new ColumnMetadata('price', 'decimal', 'decimal', precision: 12, scale: 2),
            new ColumnMetadata('active', 'boolean', 'boolean', defaultValue: true, hasDefault: true),
            new ColumnMetadata('created_at', 'timestamp', 'datetime', nullable: true),
            new ColumnMetadata('updated_at', 'timestamp', 'datetime', nullable: true),
        ]);

        $metadata = (new MetadataEnricher())->enrich($raw);
        self::assertSame('decimal:2', $metadata->casts['price']);
        self::assertSame('exists:categories,id', substr($metadata->storeRules['category_id'], -20));
        self::assertSame('category', $metadata->relationships[0]->name);
        self::assertSame(['name'], $metadata->searchable);
        self::assertContains('category_id', $metadata->filterable);
        self::assertContains('price', $metadata->sortable);
        self::assertSame(['category'], $metadata->allowedIncludes);
    }
}
