<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature\Commands;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ByfallCode\ByfallCrud\Tests\TestCase;

final class MakeEntityCommandTest extends TestCase
{
    public function test_it_generates_an_entity_from_a_migration_and_php_is_valid(): void
    {
        $migration = $this->fixtureMigration();

        $this->artisan('make:entity', [
            'name' => 'Category',
            '--source' => 'migration',
            '--migration' => $migration,
        ])->assertExitCode(0);

        self::assertFileExists(app_path('Models/Category.php'));
        self::assertFileExists(app_path('Http/Controllers/CategoryController.php'));
        self::assertFileExists(storage_path('api-collections/Category_collection.json'));

        foreach ($this->generatedPhpFiles() as $file) {
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }
    }

    public function test_no_resources_generates_a_standalone_valid_controller(): void
    {
        $migration = $this->fixtureMigration();

        $this->artisan('make:entity', [
            'name' => 'Category',
            '--source' => 'migration',
            '--migration' => $migration,
            '--no-resources' => true,
            '--no-factory' => true,
            '--no-seeder' => true,
            '--no-collection-json' => true,
        ])->assertExitCode(0);

        $controller = File::get(app_path('Http/Controllers/CategoryController.php'));
        self::assertStringNotContainsString('CategoryResource', $controller);
        self::assertStringNotContainsString('CategoryCollection', $controller);
        self::assertStringContainsString('response()->json($item, 201)', $controller);
        self::assertSame(
            str_replace("\r\n", "\n", File::get(__DIR__.'/../../Snapshots/category-controller-without-resources.php.snap')),
            str_replace("\r\n", "\n", $controller),
        );
    }

    public function test_existing_files_are_preserved_without_force_and_replaced_with_force(): void
    {
        $migration = $this->fixtureMigration();
        $arguments = ['name' => 'Category', '--source' => 'migration', '--migration' => $migration];

        $this->artisan('make:entity', $arguments)->assertExitCode(0);
        File::put(app_path('Models/Category.php'), '<?php // user change');
        File::put(storage_path('api-collections/Category_collection.json'), '{"user":"change"}');

        $this->artisan('make:entity', $arguments)->assertExitCode(0);
        self::assertSame('<?php // user change', File::get(app_path('Models/Category.php')));
        self::assertSame('{"user":"change"}', File::get(storage_path('api-collections/Category_collection.json')));

        $this->artisan('make:entity', $arguments + ['--force' => true])->assertExitCode(0);
        self::assertStringContainsString('class Category extends Model', File::get(app_path('Models/Category.php')));
        self::assertJson(File::get(storage_path('api-collections/Category_collection.json')));
    }

    public function test_it_rejects_an_invalid_source(): void
    {
        $this->artisan('make:entity', ['name' => 'Category', '--source' => 'foobar'])
            ->expectsOutput("--source doit être 'db' ou 'migration'.")
            ->assertExitCode(1);
    }

    public function test_it_reports_an_unsupported_database_driver(): void
    {
        $this->artisan('make:entity', ['name' => 'Category', '--source' => 'db'])
            ->expectsOutput('Driver non supporté : sqlite')
            ->assertExitCode(1);
    }

    public function test_missing_api_foundation_explicitly_falls_back_to_legacy_controller(): void
    {
        $this->artisan('make:entity', [
            'name' => 'Category',
            '--source' => 'migration',
            '--migration' => $this->fixtureMigration(),
            '--no-factory' => true,
            '--no-seeder' => true,
            '--no-collection-json' => true,
        ])
            ->expectsOutputToContain('API Foundation absente; Controller legacy généré.')
            ->assertExitCode(0);

        $controller = File::get(app_path('Http/Controllers/CategoryController.php'));
        self::assertStringNotContainsString('App\\Support\\ApiResponse', $controller);
        self::assertStringContainsString('response()->json', $controller);
    }

    public function test_installed_api_foundation_enables_the_smart_controller(): void
    {
        $foundation = app_path('Support/ApiResponse.php');
        File::ensureDirectoryExists(dirname($foundation));
        File::put($foundation, '<?php // installed API Foundation fixture');
        try {
            $this->artisan('make:entity', [
                'name' => 'Category',
                '--source' => 'migration',
                '--migration' => $this->fixtureMigration(),
                '--no-factory' => true,
                '--no-seeder' => true,
                '--no-collection-json' => true,
            ])->assertExitCode(0);

            $controller = File::get(app_path('Http/Controllers/CategoryController.php'));
            self::assertStringContainsString('use App\\Support\\ApiResponse;', $controller);
            self::assertStringContainsString('$request->validated()', $controller);
            self::assertStringContainsString('ApiResponse::paginated', $controller);
            self::assertStringContainsString('ApiResponse::created', $controller);
            self::assertStringContainsString('ApiResponse::noContent', $controller);
            $process = new Process([PHP_BINARY, '-l', app_path('Http/Controllers/CategoryController.php')]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        } finally {
            File::delete($foundation);
        }
    }
}
