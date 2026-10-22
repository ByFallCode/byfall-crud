<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use ByfallCode\ByfallCrud\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_registers_all_historical_commands(): void
    {
        $commands = Artisan::all();

        self::assertArrayHasKey('make:entity', $commands);
        self::assertArrayHasKey('delete:entity', $commands);
        self::assertArrayHasKey('make:api-collection', $commands);
        self::assertArrayHasKey('byfall:install', $commands);
    }

    public function test_package_discovery_requires_no_application_provider_or_global_http_hook(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/ByfallCrudServiceProvider.php');
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            ['ByfallCode\\ByfallCrud\\ByfallCrudServiceProvider'],
            $composer['extra']['laravel']['providers'],
        );
        self::assertStringNotContainsString('ByfallApiServiceProvider', $source);
        self::assertStringNotContainsString('ForceJsonResponse', $source);
        self::assertStringNotContainsString('ApiExceptionRenderer', $source);
        self::assertStringNotContainsString('shouldRenderJsonWhen', $source);
        self::assertStringNotContainsString('pushMiddlewareToGroup', $source);
    }
}
