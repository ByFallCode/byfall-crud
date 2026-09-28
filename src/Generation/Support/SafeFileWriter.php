<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation\Support;

use Illuminate\Filesystem\Filesystem;

final class SafeFileWriter
{
    public function __construct(private readonly Filesystem $files = new Filesystem()) {}

    public function write(string $path, string $content, bool $force = false): bool
    {
        if ($this->files->exists($path) && !$force) return false;
        $directory = dirname($path);
        if (!$this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }
        $this->files->put($path, $content);
        return true;
    }
}
