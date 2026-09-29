<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature\Commands;

use Illuminate\Support\Facades\File;
use ByfallCode\ByfallCrud\Tests\TestCase;

final class DeleteEntityCommandTest extends TestCase
{
    public function test_it_deletes_files_after_confirmation(): void
    {
        $path = app_path('Models/Category.php');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<?php');

        $this->artisan('delete:entity', ['name' => 'Category'])
            ->expectsConfirmation("Supprimer {$path} ?", 'yes')
            ->assertExitCode(0);

        self::assertFileDoesNotExist($path);
    }

    public function test_force_deletes_all_partially_present_files(): void
    {
        $model = app_path('Models/Category.php');
        $queries = [
            app_path('Queries/CategoryQuerySpecification.php'),
            app_path('Queries/CategoryQueryParser.php'),
            app_path('Queries/CategoryQueryApplier.php'),
        ];
        $request = app_path('Http/Requests/Category/StoreCategoryRequest.php');
        File::ensureDirectoryExists(dirname($model));
        File::ensureDirectoryExists(dirname($queries[0]));
        File::ensureDirectoryExists(dirname($request));
        File::put($model, '<?php');
        foreach ($queries as $query) {
            File::put($query, '<?php');
        }
        File::put($request, '<?php');

        $this->artisan('delete:entity', ['name' => 'Category', '--force' => true])
            ->assertExitCode(0);

        self::assertFileDoesNotExist($model);
        foreach ($queries as $query) {
            self::assertFileDoesNotExist($query);
        }
        self::assertFileDoesNotExist($request);
        self::assertDirectoryDoesNotExist(dirname($request));
    }

    public function test_generated_query_artifacts_are_removed_with_the_entity(): void
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

            $queries = [
                app_path('Queries/CategoryQuerySpecification.php'),
                app_path('Queries/CategoryQueryParser.php'),
                app_path('Queries/CategoryQueryApplier.php'),
            ];
            foreach ($queries as $query) {
                self::assertFileExists($query);
            }

            $this->artisan('delete:entity', ['name' => 'Category', '--force' => true])->assertExitCode(0);
            foreach ($queries as $query) {
                self::assertFileDoesNotExist($query);
            }
        } finally {
            File::delete($foundation);
        }
    }

    public function test_missing_entity_is_a_successful_no_op(): void
    {
        $this->artisan('delete:entity', ['name' => 'Missing', '--force' => true])
            ->expectsOutput('Aucun fichier trouvé pour Missing.')
            ->assertExitCode(0);
    }
}
