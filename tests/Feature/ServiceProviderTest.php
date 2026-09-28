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
    }
}
