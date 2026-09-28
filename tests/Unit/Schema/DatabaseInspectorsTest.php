<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Schema;

use ByfallCode\ByfallCrud\Schema\Database\MySqlSchemaInspector;
use ByfallCode\ByfallCrud\Schema\Database\PostgreSqlSchemaInspector;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseInspectorsTest extends TestCase
{
    public function test_mysql_preserves_primary_foreign_composite_unique_and_defaults(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabaseName')->willReturn('app');
        $connection->expects(self::exactly(2))->method('select')->willReturnOnConsecutiveCalls(
            [
                (object) ['column_name' => 'uuid', 'data_type' => 'char', 'is_nullable' => 'NO', 'character_maximum_length' => 36, 'column_default' => null, 'column_type' => 'char(36)', 'column_key' => 'PRI', 'extra' => ''],
                (object) ['column_name' => 'tenant_id', 'data_type' => 'bigint', 'is_nullable' => 'NO', 'column_default' => null, 'column_type' => 'bigint unsigned', 'column_key' => '', 'extra' => ''],
                (object) ['column_name' => 'slug', 'data_type' => 'varchar', 'is_nullable' => 'NO', 'character_maximum_length' => 120, 'column_default' => 'draft', 'column_type' => 'varchar(120)', 'column_key' => '', 'extra' => ''],
                (object) ['column_name' => 'created_at', 'data_type' => 'timestamp', 'is_nullable' => 'YES', 'column_default' => null, 'column_type' => 'timestamp', 'column_key' => '', 'extra' => ''],
                (object) ['column_name' => 'updated_at', 'data_type' => 'timestamp', 'is_nullable' => 'YES', 'column_default' => null, 'column_type' => 'timestamp', 'column_key' => '', 'extra' => ''],
                (object) ['column_name' => 'deleted_at', 'data_type' => 'timestamp', 'is_nullable' => 'YES', 'column_default' => null, 'column_type' => 'timestamp', 'column_key' => '', 'extra' => ''],
            ],
            [
                (object) ['constraint_name' => 'PRIMARY', 'constraint_type' => 'PRIMARY KEY', 'column_name' => 'uuid'],
                (object) ['constraint_name' => 'articles_tenant_slug_unique', 'constraint_type' => 'UNIQUE', 'column_name' => 'tenant_id'],
                (object) ['constraint_name' => 'articles_tenant_slug_unique', 'constraint_type' => 'UNIQUE', 'column_name' => 'slug'],
                (object) ['constraint_name' => 'articles_tenant_fk', 'constraint_type' => 'FOREIGN KEY', 'column_name' => 'tenant_id', 'referenced_table_name' => 'tenants', 'referenced_column_name' => 'id'],
            ],
        );

        $metadata = (new MySqlSchemaInspector($connection))->inspect('articles');
        self::assertSame('uuid', $metadata->primaryKey);
        self::assertTrue($metadata->timestamps);
        self::assertTrue($metadata->softDeletes);
        self::assertSame(['tenant_id', 'slug'], $metadata->uniqueConstraints[0]->columns);
        self::assertSame('articles_tenant_fk', $metadata->columns[1]->foreignKey?->constraintName);
        self::assertTrue($metadata->columns[2]->hasDefault);
        self::assertSame('draft', $metadata->columns[2]->defaultValue);
    }

    public function test_postgresql_preserves_custom_primary_and_generated_default(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['column_name' => 'code', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => "nextval('codes_seq')", 'column_type' => 'integer', 'is_generated' => 'NEVER']],
            [(object) ['constraint_name' => 'codes_pkey', 'constraint_type' => 'PRIMARY KEY', 'column_name' => 'code']],
        );

        $metadata = (new PostgreSqlSchemaInspector($connection))->inspect('codes');
        self::assertSame('code', $metadata->primaryKey);
        self::assertTrue($metadata->columns[0]->autoIncrement);
        self::assertTrue($metadata->columns[0]->primary);
    }
}
