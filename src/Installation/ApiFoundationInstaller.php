<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Installation;

use ByfallCode\ByfallCrud\Generation\Support\SafeFileWriter;
use Illuminate\Filesystem\Filesystem;

final class ApiFoundationInstaller
{
    private const LEGACY_ARTIFACTS = [
        'app/Providers/ByfallApiServiceProvider.php',
        'app/Http/Middleware/ForceJsonResponse.php',
        'app/Support/ApiExceptionRenderer.php',
    ];

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

        return new InstallationResult(
            $files,
            $this->legacyArtifacts($basePath),
            $this->legacyProviderIsRegistered($basePath),
        );
    }

    private function publicationMap(string $basePath): array
    {
        $root = dirname(__DIR__);
        return [
            $basePath.'/app/Support/ApiErrorCode.php' => [$root.'/Http/ApiErrorCode.php', 'App\\Support'],
            $basePath.'/app/Support/ApiResponse.php' => [$root.'/Http/ApiResponse.php', 'App\\Support'],
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

    /** @return list<string> */
    private function legacyArtifacts(string $basePath): array
    {
        return array_values(array_filter(
            self::LEGACY_ARTIFACTS,
            fn (string $relative): bool => $this->files->exists($basePath.'/'.$relative),
        ));
    }

    private function legacyProviderIsRegistered(string $basePath): bool
    {
        $path = $basePath.'/bootstrap/providers.php';
        if (!$this->files->exists($path)) return false;

        return str_contains($this->files->get($path), 'ByfallApiServiceProvider::class');
    }
}
