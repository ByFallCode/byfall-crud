<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature\Commands;

use Illuminate\Support\Facades\File;
use ByfallCode\ByfallCrud\Tests\TestCase;

final class MakeApiCollectionCommandTest extends TestCase
{
    public function test_it_generates_valid_json_from_migrations(): void
    {
        $this->fixtureMigration();
        $output = storage_path('api-collections/test.json');

        $this->artisan('make:api-collection', [
            '--source' => 'migrations',
            '--migrations' => database_path('migrations'),
            '--output' => $output,
            '--pretty' => true,
        ])->assertExitCode(0);

        self::assertFileExists($output);
        self::assertJson(File::get($output));
        $collection = json_decode(File::get($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('API Global Collection', $collection['info']['name']);
        self::assertSame('Category', $collection['item'][0]['name']);
    }

    public function test_it_rejects_an_invalid_source(): void
    {
        $this->artisan('make:api-collection', ['--source' => 'foobar'])
            ->expectsOutput("--source doit être 'db' ou 'migrations'.")
            ->assertExitCode(1);
    }

    public function test_it_reports_an_unsupported_database_driver(): void
    {
        $this->artisan('make:api-collection', [
            '--source' => 'db',
            '--output' => storage_path('api-collections/unsupported.json'),
        ])->expectsOutput('Driver non géré: sqlite')
            ->assertExitCode(1);
    }

    public function test_output_is_preserved_without_force_and_replaced_with_force(): void
    {
        $this->fixtureMigration();
        $output = storage_path('api-collections/test.json');
        File::ensureDirectoryExists(dirname($output));
        File::put($output, '{"user":"change"}');
        $arguments = [
            '--source' => 'migrations',
            '--migrations' => database_path('migrations'),
            '--output' => $output,
        ];

        $this->artisan('make:api-collection', $arguments)->assertExitCode(0);
        self::assertSame('{"user":"change"}', File::get($output));

        $this->artisan('make:api-collection', $arguments + ['--force' => true])->assertExitCode(0);
        self::assertJson(File::get($output));
        self::assertStringNotContainsString('"user"', File::get($output));
    }

    public function test_skip_pivots_uses_the_real_id_metadata(): void
    {
        $this->fixtureMigration('create_role_user_table.php');
        $this->fixtureMigration('create_memberships_table.php');
        $output = storage_path('api-collections/pivots.json');

        $this->artisan('make:api-collection', [
            '--source' => 'migrations',
            '--migrations' => database_path('migrations'),
            '--output' => $output,
            '--skip-pivots' => true,
        ])->assertExitCode(0);

        $collection = json_decode(File::get($output), true, 512, JSON_THROW_ON_ERROR);
        $names = array_column($collection['item'], 'name');
        self::assertNotContains('RoleUser', $names);
        self::assertContains('Membership', $names);
    }
}
