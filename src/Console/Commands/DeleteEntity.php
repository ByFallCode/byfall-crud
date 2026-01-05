<?php
namespace ByfallCode\ByfallCrud\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DeleteEntity extends Command
{
    protected $signature = 'delete:entity {name : Nom d\'entité en StudlyCase, ex: Post} {--force : Supprimer sans confirmation}';
    protected $description = "Supprime tous les fichiers générés par make:entity pour une entité donnée.";

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));

        $paths = [
            app_path("Models/{$name}.php"),
            app_path("Repositories/{$name}Repository.php"),
            app_path("Http/Controllers/{$name}Controller.php"),
            app_path("Http/Requests/{$name}/Store{$name}Request.php"),
            app_path("Http/Requests/{$name}/Update{$name}Request.php"),
            app_path("Http/Resources/{$name}Resource.php"),
            app_path("Http/Resources/{$name}Collection.php"),
            base_path("database/factories/{$name}Factory.php"),
            base_path("database/seeders/{$name}Seeder.php"),
            storage_path("api-collections/{$name}_collection.json"),
        ];

        $deleted = [];

        foreach ($paths as $path) {
            if (File::exists($path)) {
                if (!$this->option('force') && !$this->confirm("Supprimer {$path} ?")) {
                    continue;
                }
                File::delete($path);
                $deleted[] = $path;
            }
        }

        if (empty($deleted)) {
            $this->warn("Aucun fichier trouvé pour {$name}.");
            return self::SUCCESS;
        }

        foreach ($deleted as $file) {
            $this->info("🗑️ Supprimé : {$file}");
        }

        // Si le dossier Http/Requests/{Entity} est vide → supprime le dossier
        $reqDir = app_path("Http/Requests/{$name}");
        if (File::isDirectory($reqDir) && empty(File::files($reqDir))) {
            File::deleteDirectory($reqDir);
            $this->info("🗑️ Supprimé : {$reqDir}");
        }

        return self::SUCCESS;
    }
}
