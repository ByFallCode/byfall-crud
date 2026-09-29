<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Installation;

use ByfallCode\ByfallCrud\Installation\ApiFoundationInstaller;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ApiFoundationInstallerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'Byfall Test User'.DIRECTORY_SEPARATOR
            .'My Laravel App '.bin2hex(random_bytes(6));
        (new Filesystem())->ensureDirectoryExists($this->basePath.'/bootstrap');
        file_put_contents($this->basePath.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->basePath);
    }

    public function test_first_second_and_third_installations_are_idempotent(): void
    {
        $installer = new ApiFoundationInstaller();
        $first = $installer->install($this->basePath);
        $snapshot = $this->snapshot();
        $second = $installer->install($this->basePath);
        $third = $installer->install($this->basePath);

        self::assertTrue($first->complete);
        self::assertCount(5, $first->files);
        self::assertSame('configured', $first->integration);
        self::assertTrue($second->complete);
        self::assertSame('already_configured', $second->integration);
        self::assertSame('already_configured', $third->integration);
        self::assertSame($snapshot, $this->snapshot());
        self::assertSame(1, substr_count(file_get_contents($this->basePath.'/bootstrap/providers.php'), ApiFoundationInstaller::PROVIDER.'::class'));
        self::assertSame(1, substr_count(file_get_contents($this->basePath.'/app/Providers/ByfallApiServiceProvider.php'), 'pushMiddlewareToGroup'));
    }

    public function test_standard_file_with_imported_provider_is_registered_automatically(): void
    {
        $path = $this->basePath.'/bootstrap/providers.php';
        file_put_contents($path, "<?php\r\n\r\nuse App\\Providers\\AppServiceProvider;\r\n\r\nreturn [\r\n    AppServiceProvider::class,\r\n];\r\n");

        $result = (new ApiFoundationInstaller())->install($this->basePath);
        $content = file_get_contents($path);

        self::assertTrue($result->complete);
        self::assertSame('configured', $result->integration);
        self::assertStringContainsString('App\\Providers\\AppServiceProvider::class,', $content);
        self::assertSame(1, substr_count($content, ApiFoundationInstaller::PROVIDER.'::class'));
    }

    public function test_imported_file_with_provider_already_registered_is_connected_without_rewrite(): void
    {
        $path = $this->basePath.'/bootstrap/providers.php';
        $content = "<?php\r\n\r\nuse App\\Providers\\AppServiceProvider;\r\nuse App\\Providers\\ByfallApiServiceProvider;\r\n\r\nreturn [\r\n    AppServiceProvider::class,\r\n    ByfallApiServiceProvider::class,\r\n];\r\n";
        file_put_contents($path, $content);

        $result = (new ApiFoundationInstaller())->install($this->basePath);

        self::assertTrue($result->complete);
        self::assertSame('already_configured', $result->integration);
        self::assertSame($content, file_get_contents($path));
    }

    public function test_existing_files_are_preserved_without_force_and_replaced_with_force(): void
    {
        $path = $this->basePath.'/app/Support/ApiResponse.php';
        (new Filesystem())->ensureDirectoryExists(dirname($path));
        file_put_contents($path, '<?php // application customization');

        $installer = new ApiFoundationInstaller();
        $preserved = $installer->install($this->basePath);
        self::assertSame('preserved', $preserved->files[$path]);
        self::assertSame('<?php // application customization', file_get_contents($path));

        $replaced = $installer->install($this->basePath, true);
        self::assertSame('installed', $replaced->files[$path]);
        self::assertStringContainsString('final class ApiResponse', file_get_contents($path));
    }

    public function test_unrecognized_bootstrap_is_never_rewritten_and_partial_state_is_reported(): void
    {
        $path = $this->basePath.'/bootstrap/providers.php';
        $custom = "<?php\n// custom dynamic provider logic\nreturn load_providers();\n";
        file_put_contents($path, $custom);

        $result = (new ApiFoundationInstaller())->install($this->basePath, true);

        self::assertFalse($result->complete);
        self::assertSame('manual_required', $result->integration);
        self::assertSame($custom, file_get_contents($path));
        self::assertFileExists($this->basePath.'/app/Support/ApiResponse.php');
    }

    public function test_bootstrap_app_is_preserved_byte_for_byte(): void
    {
        $path = $this->basePath.'/bootstrap/app.php';
        $content = "<?php\r\n// application bootstrap customization\r\nreturn 'untouched';\r\n";
        file_put_contents($path, $content);

        $result = (new ApiFoundationInstaller())->install($this->basePath, true);

        self::assertTrue($result->complete);
        self::assertSame($content, file_get_contents($path));
        self::assertSame(hash('sha256', $content), hash_file('sha256', $path));
    }

    public function test_missing_bootstrap_provider_file_is_reported_without_unsafe_creation(): void
    {
        unlink($this->basePath.'/bootstrap/providers.php');
        $result = (new ApiFoundationInstaller())->install($this->basePath);

        self::assertFalse($result->complete);
        self::assertSame('manual_required', $result->integration);
        self::assertFileDoesNotExist($this->basePath.'/bootstrap/providers.php');
        self::assertFileExists($this->basePath.'/app/Providers/ByfallApiServiceProvider.php');
    }

    public function test_published_code_is_valid_autonomous_application_php(): void
    {
        $result = (new ApiFoundationInstaller())->install($this->basePath);
        self::assertTrue($result->complete);
        foreach (array_keys($result->files) as $file) {
            $content = file_get_contents($file);
            self::assertStringNotContainsString('ByfallCode\\ByfallCrud', $content);
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }
    }

    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath));
        foreach ($iterator as $file) {
            if ($file->isFile()) $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    }

    public function test_installer_scope_excludes_sensitive_and_unrelated_application_files(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Installation/ApiFoundationInstaller.php');
        foreach (['.env', 'auth.json', 'composer.json', 'Sanctum', 'database/migrations', 'User.php', 'bootstrap/app.php'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
