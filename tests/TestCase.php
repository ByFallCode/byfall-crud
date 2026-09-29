<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests;

use ByfallCode\ByfallCrud\ByfallCrudServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTestArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanTestArtifacts();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ByfallCrudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function fixtureMigration(string $name = 'create_categories_table.php'): string
    {
        $source = __DIR__.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Migrations'.DIRECTORY_SEPARATOR.$name;
        $targetDir = database_path('migrations');
        File::ensureDirectoryExists($targetDir);
        $target = $targetDir.DIRECTORY_SEPARATOR.$name;
        File::copy($source, $target);

        return $target;
    }

    protected function generatedPhpFiles(): array
    {
        return array_values(array_filter([
            app_path('Models/Category.php'),
            app_path('Queries/CategoryQuerySpecification.php'),
            app_path('Queries/CategoryQueryParser.php'),
            app_path('Queries/CategoryQueryApplier.php'),
            app_path('Repositories/CategoryRepository.php'),
            app_path('Http/Controllers/CategoryController.php'),
            app_path('Http/Requests/Category/StoreCategoryRequest.php'),
            app_path('Http/Requests/Category/UpdateCategoryRequest.php'),
            app_path('Http/Resources/CategoryResource.php'),
            app_path('Http/Resources/CategoryCollection.php'),
            database_path('factories/CategoryFactory.php'),
            database_path('seeders/CategorySeeder.php'),
        ], static fn (string $path): bool => File::exists($path)));
    }

    private function cleanTestArtifacts(): void
    {
        File::delete([
            app_path('Models/Category.php'),
            app_path('Queries/CategoryQuerySpecification.php'),
            app_path('Queries/CategoryQueryParser.php'),
            app_path('Queries/CategoryQueryApplier.php'),
            app_path('Repositories/CategoryRepository.php'),
            app_path('Http/Controllers/CategoryController.php'),
            app_path('Http/Requests/Category/StoreCategoryRequest.php'),
            app_path('Http/Requests/Category/UpdateCategoryRequest.php'),
            app_path('Http/Resources/CategoryResource.php'),
            app_path('Http/Resources/CategoryCollection.php'),
            database_path('factories/CategoryFactory.php'),
            database_path('seeders/CategorySeeder.php'),
            storage_path('api-collections/Category_collection.json'),
            storage_path('api-collections/test.json'),
            storage_path('api-collections/pivots.json'),
            storage_path('api-collections/unsupported.json'),
            database_path('migrations/create_categories_table.php'),
            database_path('migrations/create_role_user_table.php'),
            database_path('migrations/create_memberships_table.php'),
        ]);

        $requestDirectory = app_path('Http/Requests/Category');
        if (File::isDirectory($requestDirectory) && File::files($requestDirectory) === []) {
            File::deleteDirectory($requestDirectory);
        }
    }
}
