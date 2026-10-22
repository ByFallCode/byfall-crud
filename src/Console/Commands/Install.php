<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Console\Commands;

use ByfallCode\ByfallCrud\Installation\ApiFoundationInstaller;
use Illuminate\Console\Command;

final class Install extends Command
{
    protected $signature = 'byfall:install {--force : Replace the published API response classes}';
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

        if ($result->hasLegacyFoundation()) {
            $this->newLine();
            $this->components->warn('Legacy Byfall API Foundation detected.');
            foreach ($result->legacyArtifacts as $artifact) {
                $this->line('  - '.$artifact);
            }
            if ($result->legacyProviderRegistered) {
                $this->line('  - App\\Providers\\ByfallApiServiceProvider::class in bootstrap/providers.php');
            }
            $this->line('Review local customizations before removing these legacy elements.');
            $this->line('No legacy file or bootstrap entry was changed.');
        }

        $this->info('Byfall CRUD is ready.');
        return self::SUCCESS;
    }
}
