<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature\Commands;

use ByfallCode\ByfallCrud\Installation\ApiFoundationInstaller;
use ByfallCode\ByfallCrud\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

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
        file_put_contents($this->temporaryBase.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
        $this->app->setBasePath($this->temporaryBase);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        (new Filesystem())->deleteDirectory($this->temporaryBase);
        parent::tearDown();
    }

    public function test_command_installs_and_then_reports_an_idempotent_result(): void
    {
        $this->artisan('byfall:install')->assertExitCode(0);
        $this->artisan('byfall:install')->expectsOutputToContain('Nothing to change.')->assertExitCode(0);
        $this->artisan('byfall:install')->assertExitCode(0);

        self::assertFileExists($this->temporaryBase.'/app/Support/ApiResponse.php');
        self::assertSame(1, substr_count(
            file_get_contents($this->temporaryBase.'/bootstrap/providers.php'),
            ApiFoundationInstaller::PROVIDER.'::class',
        ));
    }

    public function test_command_reports_partial_installation_for_unknown_bootstrap_shape(): void
    {
        $path = $this->temporaryBase.'/bootstrap/providers.php';
        $custom = "<?php\nreturn custom_providers();\n";
        file_put_contents($path, $custom);

        $this->artisan('byfall:install', ['--force' => true])
            ->expectsOutputToContain('Installation is partial')
            ->assertExitCode(1);

        self::assertSame($custom, file_get_contents($path));
    }
}
