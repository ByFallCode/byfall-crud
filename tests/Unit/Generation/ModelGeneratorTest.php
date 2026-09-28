<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\ModelGenerator;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\RelationshipMetadata;
use PHPUnit\Framework\TestCase;

final class ModelGeneratorTest extends TestCase
{
    public function test_it_renders_metadata_driven_model_features(): void
    {
        $metadata = new EntityMetadata(
            name: 'Product', table: 'catalog_products', primaryKey: 'uuid',
            columns: [new ColumnMetadata('uuid', normalizedType: 'string', primary: true)],
            relationships: [
                new RelationshipMetadata('author', 'belongsTo', 'User', 'author_uuid', 'uuid'),
                new RelationshipMetadata('unsafe', 'belongsTo', 'Team', 'team_ref', ambiguous: true, confidence: 0.6),
            ],
            timestamps: false, softDeletes: true,
            fillable: ['name', 'price'], hidden: ['secret'],
            casts: ['price' => 'decimal:2', 'active' => 'boolean'],
        );

        $code = (new ModelGenerator())->generate($metadata, new GenerationContext('Product'));

        self::assertStringContainsString("protected \$table = 'catalog_products';", $code);
        self::assertStringContainsString("protected \$primaryKey = 'uuid';", $code);
        self::assertStringContainsString('public $incrementing = false;', $code);
        self::assertStringContainsString("protected \$keyType = 'string';", $code);
        self::assertStringContainsString('public $timestamps = false;', $code);
        self::assertStringContainsString('use SoftDeletes;', $code);
        self::assertStringContainsString("'name',", $code);
        self::assertStringContainsString("protected \$hidden", $code);
        self::assertStringContainsString("'price' => 'decimal:2'", $code);
        self::assertStringContainsString('public function author(): BelongsTo', $code);
        self::assertStringContainsString("User::class, 'author_uuid', 'uuid'", $code);
        self::assertStringNotContainsString('function unsafe', $code);
    }

    public function test_standard_incrementing_primary_key_and_timestamps_need_no_overrides(): void
    {
        $metadata = new EntityMetadata('User', 'users', columns: [
            new ColumnMetadata('id', normalizedType: 'integer', primary: true, autoIncrement: true),
        ], fillable: ['name']);

        $code = (new ModelGenerator())->generate($metadata, new GenerationContext('User'));

        self::assertStringNotContainsString('$primaryKey', $code);
        self::assertStringNotContainsString('$incrementing', $code);
        self::assertStringNotContainsString('$timestamps', $code);
    }
}
