<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Schema;

use ByfallCode\ByfallCrud\Schema\Migration\MigrationSchemaSource;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class MigrationSchemaSourceTest extends TestCase
{
    private MigrationSchemaSource $source;

    protected function setUp(): void
    {
        $this->source = new MigrationSchemaSource(new Filesystem());
    }

    public function test_it_builds_native_metadata_with_schema_facts(): void
    {
        $metadata = $this->source->inspect(__DIR__.'/../../Fixtures/Migrations/create_articles_table.php', 'Article');

        self::assertSame('articles', $metadata->table);
        self::assertSame('code', $metadata->primaryKey);
        self::assertTrue($metadata->timestamps);
        self::assertTrue($metadata->softDeletes);
        self::assertSame(['tenant_id', 'slug'], $metadata->uniqueConstraints[0]->columns);
        self::assertSame('articles_tenant_slug_unique', $metadata->uniqueConstraints[0]->name);

        $columns = [];
        foreach ($metadata->columns as $column) $columns[$column->name] = $column;
        self::assertTrue($columns['active']->hasDefault);
        self::assertTrue($columns['active']->defaultValue);
        self::assertTrue($columns['summary']->hasDefault);
        self::assertNull($columns['summary']->defaultValue);
        self::assertTrue($columns['summary']->nullable);
        self::assertSame('tenants', $columns['tenant_id']->foreignKey?->referencedTable);
    }

    public function test_it_reports_unsupported_schema_table_constructs(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schema::table');
        $this->source->inspect(__DIR__.'/../../Fixtures/Migrations/alter_articles_table.php');
    }

    public function test_it_preserves_simple_unique_nullable_and_timestamps(): void
    {
        $metadata = $this->source->inspect(__DIR__.'/../../Fixtures/Migrations/create_categories_table.php');
        $columns = [];
        foreach ($metadata->columns as $column) $columns[$column->name] = $column;

        self::assertSame(['name'], $metadata->uniqueConstraints[0]->columns);
        self::assertTrue($columns['name']->unique);
        self::assertTrue($columns['description']->nullable);
        self::assertTrue($metadata->timestamps);
    }
}
