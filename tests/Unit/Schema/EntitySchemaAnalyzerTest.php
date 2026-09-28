<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Schema;

use ByfallCode\ByfallCrud\Contracts\SchemaSource;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Schema\EntitySchemaAnalyzer;
use PHPUnit\Framework\TestCase;

final class EntitySchemaAnalyzerTest extends TestCase
{
    public function test_it_analyzes_each_entity_once_per_analyzer_instance(): void
    {
        $source = new class implements SchemaSource {
            public int $calls = 0;
            public function inspect(string $identifier, string $entityName = ''): EntityMetadata
            {
                $this->calls++;
                return new EntityMetadata($entityName, $identifier);
            }
        };
        $analyzer = new EntitySchemaAnalyzer();

        $first = $analyzer->analyze($source, 'articles', 'Article');
        $second = $analyzer->analyze($source, 'articles', 'Article');

        self::assertSame($first, $second);
        self::assertSame(1, $source->calls);
        self::assertSame(1, $analyzer->inspectionCount());
    }

    public function test_cache_keys_cannot_mix_identifiers_and_entity_names(): void
    {
        $source = new class implements SchemaSource {
            public int $calls = 0;
            public function inspect(string $identifier, string $entityName = ''): EntityMetadata
            {
                $this->calls++;
                return new EntityMetadata($entityName, $identifier);
            }
        };
        $analyzer = new EntitySchemaAnalyzer();

        $first = $analyzer->analyze($source, 'tenant|users', 'User');
        $second = $analyzer->analyze($source, 'tenant', 'users|User');

        self::assertNotSame($first, $second);
        self::assertSame('tenant|users', $first->table);
        self::assertSame('tenant', $second->table);
        self::assertSame(2, $source->calls);
        self::assertSame(2, $analyzer->inspectionCount());
    }
}
