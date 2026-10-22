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
        file_put_contents($this->basePath.'/bootstrap/app.php', "<?php\r\n// custom app bootstrap\r\n");
        file_put_contents($this->basePath.'/bootstrap/providers.php', "<?php\r\n// custom providers\r\nreturn [];\r\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->basePath);
    }

    public function test_installation_publishes_only_the_non_invasive_foundation(): void
    {
        $result = (new ApiFoundationInstaller())->install($this->basePath);

        self::assertCount(2, $result->files);
        self::assertFileExists($this->basePath.'/app/Support/ApiResponse.php');
        self::assertFileExists($this->basePath.'/app/Support/ApiErrorCode.php');
        self::assertFileDoesNotExist($this->basePath.'/app/Support/ApiExceptionRenderer.php');
        self::assertFileDoesNotExist($this->basePath.'/app/Http/Middleware/ForceJsonResponse.php');
        self::assertFileDoesNotExist($this->basePath.'/app/Providers/ByfallApiServiceProvider.php');
        self::assertFalse($result->hasLegacyFoundation());
    }

    public function test_repeated_installation_is_idempotent_and_preserves_existing_files(): void
    {
        $installer = new ApiFoundationInstaller();
        $first = $installer->install($this->basePath);
        $snapshot = $this->snapshot();
        $second = $installer->install($this->basePath);
        $third = $installer->install($this->basePath);

        self::assertSame(['installed', 'installed'], array_values($first->files));
        self::assertSame(['preserved', 'preserved'], array_values($second->files));
        self::assertSame(['preserved', 'preserved'], array_values($third->files));
        self::assertSame($snapshot, $this->snapshot());
    }

    public function test_install_and_force_leave_both_bootstrap_files_byte_for_byte_unchanged(): void
    {
        $app = file_get_contents($this->basePath.'/bootstrap/app.php');
        $providers = file_get_contents($this->basePath.'/bootstrap/providers.php');
        $installer = new ApiFoundationInstaller();

        $installer->install($this->basePath);
        $installer->install($this->basePath, true);

        self::assertSame($app, file_get_contents($this->basePath.'/bootstrap/app.php'));
        self::assertSame($providers, file_get_contents($this->basePath.'/bootstrap/providers.php'));
        self::assertSame(hash('sha256', $app), hash_file('sha256', $this->basePath.'/bootstrap/app.php'));
        self::assertSame(hash('sha256', $providers), hash_file('sha256', $this->basePath.'/bootstrap/providers.php'));
    }

    public function test_force_replaces_only_the_two_supported_files(): void
    {
        $installer = new ApiFoundationInstaller();
        $installer->install($this->basePath);
        $response = $this->basePath.'/app/Support/ApiResponse.php';
        $errorCode = $this->basePath.'/app/Support/ApiErrorCode.php';
        file_put_contents($response, '<?php // customized response');
        file_put_contents($errorCode, '<?php // customized codes');
        $unrelated = $this->basePath.'/app/UserFile.php';
        file_put_contents($unrelated, '<?php // untouched');

        $result = $installer->install($this->basePath, true);

        self::assertSame(['installed', 'installed'], array_values($result->files));
        self::assertStringContainsString('final class ApiResponse', file_get_contents($response));
        self::assertStringContainsString('enum ApiErrorCode', file_get_contents($errorCode));
        self::assertSame('<?php // untouched', file_get_contents($unrelated));
    }

    public function test_missing_or_dynamic_bootstrap_files_are_irrelevant_and_never_created_or_rewritten(): void
    {
        unlink($this->basePath.'/bootstrap/app.php');
        $dynamic = "<?php\nreturn custom_providers();\n";
        file_put_contents($this->basePath.'/bootstrap/providers.php', $dynamic);

        $result = (new ApiFoundationInstaller())->install($this->basePath);

        self::assertCount(2, $result->files);
        self::assertFileDoesNotExist($this->basePath.'/bootstrap/app.php');
        self::assertSame($dynamic, file_get_contents($this->basePath.'/bootstrap/providers.php'));
    }

    public function test_legacy_installation_is_detected_without_being_modified_or_deleted(): void
    {
        $legacy = [
            'app/Providers/ByfallApiServiceProvider.php',
            'app/Http/Middleware/ForceJsonResponse.php',
            'app/Support/ApiExceptionRenderer.php',
        ];
        foreach ($legacy as $relative) {
            (new Filesystem())->ensureDirectoryExists(dirname($this->basePath.'/'.$relative));
            file_put_contents($this->basePath.'/'.$relative, '<?php // customized legacy file');
        }
        $providers = "<?php\nreturn [App\\Providers\\ByfallApiServiceProvider::class];\n";
        file_put_contents($this->basePath.'/bootstrap/providers.php', $providers);

        $result = (new ApiFoundationInstaller())->install($this->basePath, true);

        self::assertTrue($result->hasLegacyFoundation());
        self::assertSame($legacy, $result->legacyArtifacts);
        self::assertTrue($result->legacyProviderRegistered);
        foreach ($legacy as $relative) {
            self::assertSame('<?php // customized legacy file', file_get_contents($this->basePath.'/'.$relative));
        }
        self::assertSame($providers, file_get_contents($this->basePath.'/bootstrap/providers.php'));
    }

    public function test_published_code_is_valid_autonomous_application_php(): void
    {
        $result = (new ApiFoundationInstaller())->install($this->basePath);
        foreach (array_keys($result->files) as $file) {
            self::assertStringNotContainsString('ByfallCode\\ByfallCrud', file_get_contents($file));
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }
    }

    public function test_installer_scope_excludes_sensitive_and_unrelated_application_files(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Installation/ApiFoundationInstaller.php');
        foreach (['.env', 'auth.json', 'composer.json', 'Sanctum', 'database/migrations', 'User.php', 'bootstrap/app.php'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
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
}
