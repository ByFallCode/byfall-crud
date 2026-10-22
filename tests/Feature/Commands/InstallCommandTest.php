<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature\Commands;

use ByfallCode\ByfallCrud\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Filesystem\Filesystem;
use NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler as CollisionExceptionHandler;

final class InstallCommandTest extends TestCase
{
    private string $temporaryBase;
    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->temporaryBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'byfall-command-'.bin2hex(random_bytes(6));
        (new Filesystem())->ensureDirectoryExists($this->temporaryBase.'/bootstrap');
        file_put_contents($this->temporaryBase.'/bootstrap/app.php', "<?php\n// untouched app\n");
        file_put_contents($this->temporaryBase.'/bootstrap/providers.php', "<?php\n// untouched providers\nreturn [];\n");
        $this->app->setBasePath($this->temporaryBase);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        (new Filesystem())->deleteDirectory($this->temporaryBase);
        parent::tearDown();
    }

    public function test_command_installs_only_two_files_and_is_idempotent(): void
    {
        $app = file_get_contents($this->temporaryBase.'/bootstrap/app.php');
        $providers = file_get_contents($this->temporaryBase.'/bootstrap/providers.php');
        $middleware = $this->app['router']->getMiddlewareGroups()['api'] ?? [];

        $this->artisan('byfall:install')->expectsOutputToContain('Byfall CRUD is ready.')->assertExitCode(0);
        $this->artisan('byfall:install')->expectsOutputToContain('already exists; preserved')->assertExitCode(0);
        $this->artisan('byfall:install')->assertExitCode(0);

        self::assertFileExists($this->temporaryBase.'/app/Support/ApiResponse.php');
        self::assertFileExists($this->temporaryBase.'/app/Support/ApiErrorCode.php');
        self::assertFileDoesNotExist($this->temporaryBase.'/app/Providers/ByfallApiServiceProvider.php');
        self::assertFileDoesNotExist($this->temporaryBase.'/app/Http/Middleware/ForceJsonResponse.php');
        self::assertFileDoesNotExist($this->temporaryBase.'/app/Support/ApiExceptionRenderer.php');
        self::assertSame($app, file_get_contents($this->temporaryBase.'/bootstrap/app.php'));
        self::assertSame($providers, file_get_contents($this->temporaryBase.'/bootstrap/providers.php'));
        self::assertSame($middleware, $this->app['router']->getMiddlewareGroups()['api'] ?? []);
    }

    public function test_force_does_not_touch_bootstrap_or_unrelated_files(): void
    {
        $this->artisan('byfall:install')->assertExitCode(0);
        $app = file_get_contents($this->temporaryBase.'/bootstrap/app.php');
        $providers = file_get_contents($this->temporaryBase.'/bootstrap/providers.php');
        $unrelated = $this->temporaryBase.'/app/custom.php';
        file_put_contents($unrelated, '<?php // custom');

        $this->artisan('byfall:install', ['--force' => true])->assertExitCode(0);

        self::assertSame($app, file_get_contents($this->temporaryBase.'/bootstrap/app.php'));
        self::assertSame($providers, file_get_contents($this->temporaryBase.'/bootstrap/providers.php'));
        self::assertSame('<?php // custom', file_get_contents($unrelated));
    }

    public function test_legacy_foundation_is_reported_but_never_removed(): void
    {
        $legacy = [
            'app/Providers/ByfallApiServiceProvider.php',
            'app/Http/Middleware/ForceJsonResponse.php',
            'app/Support/ApiExceptionRenderer.php',
        ];
        foreach ($legacy as $relative) {
            (new Filesystem())->ensureDirectoryExists(dirname($this->temporaryBase.'/'.$relative));
            file_put_contents($this->temporaryBase.'/'.$relative, '<?php // customized legacy');
        }
        $providers = "<?php\nreturn [App\\Providers\\ByfallApiServiceProvider::class];\n";
        file_put_contents($this->temporaryBase.'/bootstrap/providers.php', $providers);

        $this->artisan('byfall:install', ['--force' => true])
            ->expectsOutputToContain('Legacy Byfall API Foundation detected.')
            ->expectsOutputToContain('Review local customizations before removing these legacy elements.')
            ->assertExitCode(0);

        foreach ($legacy as $relative) {
            self::assertSame('<?php // customized legacy', file_get_contents($this->temporaryBase.'/'.$relative));
        }
        self::assertSame($providers, file_get_contents($this->temporaryBase.'/bootstrap/providers.php'));
    }

    public function test_collision_can_boot_all_artisan_commands_without_application_foundation(): void
    {
        $laravelHandler = $this->app->make(ExceptionHandlerContract::class);
        $this->app->instance(
            ExceptionHandlerContract::class,
            new CollisionExceptionHandler($this->app, $laravelHandler),
        );

        $this->artisan('list')->assertExitCode(0);
        $this->artisan('byfall:install')->assertExitCode(0);
        $this->artisan('make:entity', ['name' => 'Post', '--source' => 'db'])
            ->expectsOutput('Driver non supporté : sqlite')
            ->assertExitCode(1);

        self::assertFileDoesNotExist($this->temporaryBase.'/app/Providers/ByfallApiServiceProvider.php');
    }
}
