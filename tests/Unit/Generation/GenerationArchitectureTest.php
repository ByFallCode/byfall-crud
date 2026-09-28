<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use PHPUnit\Framework\TestCase;

final class GenerationArchitectureTest extends TestCase
{
    public function test_smart_generators_only_render_metadata(): void
    {
        $directory = dirname(__DIR__, 3).'/src/Generation';
        $sources = '';
        foreach (glob($directory.'/*.php') ?: [] as $file) $sources .= file_get_contents($file);

        foreach (['information_schema', 'pg_catalog', 'MigrationSchemaSource', 'DatabaseSchemaInspector', 'SensitiveFieldPolicy', 'Schema::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sources);
        }
        self::assertStringNotContainsString('File::put', $sources);
        self::assertStringNotContainsString('belongsTo(', file_get_contents($directory.'/ResourceGenerator.php'));
    }
}
