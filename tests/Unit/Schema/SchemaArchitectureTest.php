<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

final class SchemaArchitectureTest extends TestCase
{
    public function test_commands_share_the_schema_analyzer_and_do_not_inspect_schema_directly(): void
    {
        foreach (['MakeEntity.php', 'MakeApiCollection.php'] as $command) {
            $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/'.$command);
            self::assertStringContainsString('EntitySchemaAnalyzer', $source);
            self::assertDoesNotMatchRegularExpression('/information_schema|pg_catalog|Schema::create/', $source);
        }
    }
}
