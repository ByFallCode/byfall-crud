<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Console\Commands;

use ByfallCode\ByfallCrud\Installation\ApiFoundationInstaller;
use Illuminate\Console\Command;

final class Install extends Command
{
    protected $signature = 'byfall:install {--force : Replace published API Foundation classes}';
    protected $description = 'Install the Byfall CRUD API Foundation into the Laravel application.';

    public function handle(): int
    {
        $this->components->info('Byfall CRUD API Foundation');
        $result = (new ApiFoundationInstaller())->install(base_path(), (bool) $this->option('force'));

        foreach ($result->files as $path => $status) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $status === 'installed'
                ? $this->components->task($relative, static fn (): bool => true)
                : $this->line("  ✓ {$relative} already exists; preserved");
        }

        if (!$result->complete) {
            $this->components->warn('API exception handling requires manual configuration.');
            $this->line('Add this provider to bootstrap/providers.php:');
            $this->line('    '.ApiFoundationInstaller::PROVIDER.'::class,');
            $this->error('Installation is partial; generated files are safe, but the API Foundation is not connected.');
            return self::FAILURE;
        }

        $this->line($result->integration === 'configured'
            ? '  ✓ API exception handling configured'
            : '  ✓ API exception handling already configured');
        $this->info($result->integration === 'already_configured' ? 'Nothing to change.' : 'Byfall CRUD is ready.');
        return self::SUCCESS;
    }
}
