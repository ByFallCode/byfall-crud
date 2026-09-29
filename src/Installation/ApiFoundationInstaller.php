<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Installation;

use ByfallCode\ByfallCrud\Generation\Support\SafeFileWriter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class ApiFoundationInstaller
{
    public const PROVIDER = 'App\\Providers\\ByfallApiServiceProvider';

    public function __construct(
        private readonly Filesystem $files = new Filesystem(),
        private readonly SafeFileWriter $writer = new SafeFileWriter(),
    ) {}

    public function install(string $basePath, bool $force = false): InstallationResult
    {
        $files = [];
        foreach ($this->publicationMap($basePath) as $destination => [$source, $namespace]) {
            $content = $this->publishedSource($source, $namespace);
            $files[$destination] = $this->writer->write($destination, $content, $force)
                ? 'installed'
                : 'preserved';
        }

        $providerPath = $basePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Providers'
            .DIRECTORY_SEPARATOR.'ByfallApiServiceProvider.php';
        $files[$providerPath] = $this->writer->write($providerPath, $this->providerSource(), $force)
            ? 'installed'
            : 'preserved';

        $bootstrapProviders = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'providers.php';
        if (!$this->files->exists($bootstrapProviders) || !$this->isRecognizedProvidersFile($bootstrapProviders)) {
            return new InstallationResult($files, 'manual_required', false);
        }

        $alreadyRegistered = $this->providerIsRegistered($this->files->get($bootstrapProviders));
        if (!$alreadyRegistered) {
            $original = $this->files->get($bootstrapProviders);
            try {
                if (!ServiceProvider::addProviderToBootstrapFile(self::PROVIDER, $bootstrapProviders)) {
                    return new InstallationResult($files, 'manual_required', false);
                }
            } catch (Throwable) {
                $this->files->put($bootstrapProviders, $original);
                return new InstallationResult($files, 'manual_required', false);
            }
        }

        return new InstallationResult($files, $alreadyRegistered ? 'already_configured' : 'configured', true);
    }

    private function publicationMap(string $basePath): array
    {
        $root = dirname(__DIR__);
        return [
            $basePath.'/app/Support/ApiErrorCode.php' => [$root.'/Http/ApiErrorCode.php', 'App\\Support'],
            $basePath.'/app/Support/ApiResponse.php' => [$root.'/Http/ApiResponse.php', 'App\\Support'],
            $basePath.'/app/Support/ApiExceptionRenderer.php' => [$root.'/Http/ApiExceptionRenderer.php', 'App\\Support'],
            $basePath.'/app/Http/Middleware/ForceJsonResponse.php' => [$root.'/Http/Middleware/ForceJsonResponse.php', 'App\\Http\\Middleware'],
        ];
    }

    private function publishedSource(string $source, string $namespace): string
    {
        $content = $this->files->get($source);
        return (string) preg_replace(
            '/namespace ByfallCode\\\\ByfallCrud\\\\Http(?:\\\\Middleware)?;/',
            'namespace '.$namespace.';',
            $content,
            1,
        );
    }

    private function isRecognizedProvidersFile(string $path): bool
    {
        $content = $this->files->get($path);
        return preg_match(
            '~\A<\?php\s+(?:use\s+[A-Za-z_][A-Za-z0-9_\\\\]*\s*;\s*)*return\s*\[\s*(?:[A-Za-z_][A-Za-z0-9_\\\\]*::class\s*,\s*)*\];\s*\z~',
            $content,
        ) === 1;
    }

    private function providerIsRegistered(string $content): bool
    {
        if (str_contains($content, self::PROVIDER.'::class')) {
            return true;
        }

        return preg_match(
            '~use\s+'.preg_quote(self::PROVIDER, '~').'\s*;~',
            $content,
        ) === 1 && str_contains($content, 'ByfallApiServiceProvider::class');
    }

    private function providerSource(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiExceptionRenderer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class ByfallApiServiceProvider extends ServiceProvider
{
    public function boot(Router $router, ExceptionHandler $handler, ApiExceptionRenderer $renderer): void
    {
        $router->pushMiddlewareToGroup('api', ForceJsonResponse::class);
        $handler->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $exception): bool =>
                $request->is('api/*') || $request->expectsJson(),
        );
        $handler->renderable(
            static fn (Throwable $exception, Request $request) =>
                $renderer->render($request, $exception, (bool) config('app.debug')),
        );
    }
}
PHP;
    }
}
