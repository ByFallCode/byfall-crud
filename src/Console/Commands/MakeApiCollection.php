<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Console\Commands;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Schema\Database\DatabaseSchemaSource;
use ByfallCode\ByfallCrud\Schema\EntitySchemaAnalyzer;
use ByfallCode\ByfallCrud\Schema\Migration\MigrationSchemaSource;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeApiCollection extends Command
{
    protected $signature = 'make:api-collection
        {--source=db : db|migrations}
        {--migrations=database/migrations : Dossier des migrations (si --source=migrations)}
        {--only= : Liste de tables à inclure, séparées par des virgules}
        {--except= : Liste de tables à exclure, séparées par des virgules}
        {--output= : Chemin du JSON de sortie (défaut: storage/api-collections/_ALL_collection.json)}
        {--base-url=http://localhost:8000 : Base URL variable par défaut}
        {--skip-pivots : Tenter d’ignorer les tables pivot (2 FKs, pas d’ID auto)}
        {--pretty : Beautifier le JSON}
        {--force : Écraser le fichier de sortie existant}
    ';

    protected $description = 'Génère une collection Postman globale (CRUD) pour toute la base ou toutes les migrations — sans vues.';

    public function handle(): int
    {
        $source = $this->option('source') ?: 'db';
        $outPath = $this->option('output') ?: storage_path('api-collections/_ALL_collection.json');
        $only = $this->csvToArray((string) $this->option('only'));
        $except = $this->csvToArray((string) $this->option('except'));

        if (!in_array($source, ['db', 'migrations'], true)) {
            $this->error("--source doit être 'db' ou 'migrations'.");
            return self::FAILURE;
        }
        if (File::exists($outPath) && !$this->option('force')) {
            $this->warn("Le fichier de sortie existe déjà: {$outPath}. Utilisez --force pour l'écraser.");
            return self::SUCCESS;
        }
        try {
            $metadata = $source === 'db'
                ? $this->fromDatabase($only, $except)
                : $this->fromMigrations((string) $this->option('migrations'), $only, $except);
        } catch (\RuntimeException $exception) {
            $message = str_replace('Driver non supporté :', 'Driver non géré:', $exception->getMessage());
            $this->error($message);
            return self::FAILURE;
        }
        if ((bool) $this->option('skip-pivots')) {
            $metadata = array_filter($metadata, function (EntityMetadata $entity): bool {
                if (!$this->isPivot($entity)) return true;
                $this->line("↷ Ignoré (pivot présumé) : {$entity->table}");
                return false;
            });
        }
        if ($metadata === []) {
            $this->warn('Aucune table détectée.');
            return self::SUCCESS;
        }

        $flags = JSON_UNESCAPED_SLASHES | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0);
        $dir = dirname($outPath);
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        File::put($outPath, json_encode($this->buildCollection($metadata, (string) $this->option('base-url')), $flags));
        $this->info("✅ Collection générée : {$outPath}");
        return self::SUCCESS;
    }

    /** @return array<string, EntityMetadata> */
    private function fromDatabase(array $only, array $except): array
    {
        $source = new DatabaseSchemaSource(DB::connection());
        $analyzer = new EntitySchemaAnalyzer();
        $result = [];
        foreach ($source->tables() as $table) {
            if ($this->filtered($table, $only, $except)) continue;
            $result[$table] = $analyzer->analyze($source, $table);
        }
        return $result;
    }

    /** @return array<string, EntityMetadata> */
    private function fromMigrations(string $directory, array $only, array $except): array
    {
        $source = new MigrationSchemaSource(app(Filesystem::class));
        $analyzer = new EntitySchemaAnalyzer();
        $result = [];
        foreach ($source->migrationFiles($directory ?: base_path('database/migrations')) as $table => $file) {
            if ($this->filtered($table, $only, $except)) continue;
            $result[$table] = $analyzer->analyze($source, $file);
        }
        return $result;
    }

    /** @param array<string, EntityMetadata> $entities */
    private function buildCollection(array $entities, string $baseUrl): array
    {
        $items = [];
        foreach ($entities as $entity) {
            $slug = str_replace('_', '-', $entity->table);
            $sample = $this->sampleJson($entity);
            $items[] = [
                'name' => $entity->name,
                'item' => [
                    ['name' => 'Index', 'request' => ['method' => 'GET', 'url' => "{{base_url}}/api/{$slug}"]],
                    ['name' => 'Store', 'request' => [
                        'method' => 'POST',
                        'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                        'body' => ['mode' => 'raw', 'raw' => json_encode($sample, JSON_PRETTY_PRINT)],
                        'url' => "{{base_url}}/api/{$slug}",
                    ]],
                    ['name' => 'Show', 'request' => ['method' => 'GET', 'url' => "{{base_url}}/api/{$slug}/1"]],
                    ['name' => 'Update', 'request' => [
                        'method' => 'PUT',
                        'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                        'body' => ['mode' => 'raw', 'raw' => json_encode($sample, JSON_PRETTY_PRINT)],
                        'url' => "{{base_url}}/api/{$slug}/1",
                    ]],
                    ['name' => 'Destroy', 'request' => ['method' => 'DELETE', 'url' => "{{base_url}}/api/{$slug}/1"]],
                ],
            ];
        }
        return [
            'info' => ['name' => 'API Global Collection', '_postman_id' => (string) Str::uuid(), 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'variable' => [['key' => 'base_url', 'value' => $baseUrl ?: 'http://localhost:8000']],
            'item' => $items,
        ];
    }

    private function isPivot(EntityMetadata $entity): bool
    {
        $foreignColumns = [];
        foreach ($entity->columns as $column) if ($column->foreignKey !== null) $foreignColumns[] = $column->name;
        return $entity->primaryKey === null && count($foreignColumns) >= 2 && array_diff($entity->fillable, $foreignColumns) === [];
    }

    private function sampleJson(EntityMetadata $entity): array
    {
        $result = [];
        foreach ($entity->fillable as $field) {
            $column = null;
            foreach ($entity->columns as $candidate) if ($candidate->name === $field) { $column = $candidate; break; }
            if ($column?->foreignKey !== null) { $result[$field] = 1; continue; }
            $result[$field] = match ($entity->casts[$field] ?? 'string') {
                'integer' => 1, 'boolean' => true, 'float' => 10.5, 'array' => [],
                'date' => '2025-01-01', 'datetime' => '2025-01-01 12:00:00', default => 'exemple',
            };
        }
        return $result;
    }

    private function csvToArray(string $csv): array
    {
        return trim($csv) === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    private function filtered(string $table, array $only, array $except): bool
    {
        return (!empty($only) && !in_array($table, $only, true))
            || in_array($table, $except, true)
            || in_array($table, ['migrations', 'password_reset_tokens', 'failed_jobs', 'personal_access_tokens', 'jobs', 'job_batches'], true);
    }
}
