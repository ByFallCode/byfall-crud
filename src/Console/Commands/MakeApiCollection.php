<?php
namespace ByfallCode\ByfallCrud\Console\Commands;

use Illuminate\Console\Command;
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
    ';

    protected $description = "Génère une collection Postman globale (CRUD) pour toute la base ou toutes les migrations — sans vues.";

    public function handle(): int
    {
        $source   = $this->option('source') ?: 'db';
        $outPath  = $this->option('output') ?: storage_path('api-collections/_ALL_collection.json');
        $baseUrl  = $this->option('base-url') ?: 'http://localhost:8000';
        $only     = $this->csvToArray((string)$this->option('only'));
        $except   = $this->csvToArray((string)$this->option('except'));
        $skipPiv  = (bool)$this->option('skip-pivots');

        $tablesMeta = ($source === 'db')
            ? $this->collectFromDatabase($only, $except, $skipPiv)
            : $this->collectFromMigrations(
                (string)$this->option('migrations') ?: base_path('database/migrations'),
                $only, $except, $skipPiv
            );

        if (empty($tablesMeta)) {
            $this->warn('Aucune table détectée.');
            return self::SUCCESS;
        }

        $collection = $this->buildCollection($tablesMeta, $baseUrl);
        $flags = JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) $flags |= JSON_PRETTY_PRINT;

        $dir = dirname($outPath);
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        File::put($outPath, json_encode($collection, $flags));

        $this->info("✅ Collection générée : {$outPath}");
        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------
    // Collecte depuis la DB
    // ---------------------------------------------------------------------
    private function collectFromDatabase(array $only, array $except, bool $skipPivots): array
    {
        $driver = DB::getDriverName();
        $database = DB::getDatabaseName();

        if ($driver === 'mysql') {
            $tables = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME", [$database]);
        } elseif (in_array($driver, ['pgsql','postgres','postgresql'], true)) {
            $tables = DB::select("SELECT tablename AS TABLE_NAME FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename");
        } else {
            throw new \RuntimeException("Driver non géré: {$driver}");
        }

        $metas = [];
        foreach ($tables as $t) {
            $table = (string)($t->TABLE_NAME ?? $t->tablename ?? '');
            if ($table === '' || $this->filtered($table, $only, $except)) continue;

            // Colonnes
            if ($driver === 'mysql') {
                $cols = DB::select(<<<SQL
                    SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                    ORDER BY ORDINAL_POSITION
                SQL, [$database, $table]);

                $constraints = DB::select(<<<SQL
                    SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, tc.CONSTRAINT_TYPE,
                           kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                    LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                      ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                     AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                    WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                SQL, [$database, $table]);
            } else {
                $cols = DB::select(<<<SQL
                    SELECT column_name AS COLUMN_NAME, data_type AS DATA_TYPE, is_nullable AS IS_NULLABLE,
                           character_maximum_length AS CHARACTER_MAXIMUM_LENGTH
                    FROM information_schema.columns
                    WHERE table_schema='public' AND table_name=?
                    ORDER BY ordinal_position
                SQL, [$table]);

                $constraints = DB::select(<<<SQL
                    SELECT
                        tc.constraint_name AS CONSTRAINT_NAME,
                        kcu.column_name AS COLUMN_NAME,
                        tc.constraint_type AS CONSTRAINT_TYPE,
                        ccu.table_name AS REFERENCED_TABLE_NAME,
                        ccu.column_name AS REFERENCED_COLUMN_NAME
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                      ON tc.constraint_name=kcu.constraint_name AND tc.table_schema=kcu.table_schema
                    LEFT JOIN information_schema.constraint_column_usage ccu
                      ON ccu.constraint_name=tc.constraint_name AND ccu.table_schema=tc.table_schema
                    WHERE tc.table_schema='public' AND kcu.table_name=?
                SQL, [$table]);
            }

            // Déduire FKs
            $fks = [];
            foreach ($constraints as $c) {
                if (strtoupper((string)$c->CONSTRAINT_TYPE) === 'FOREIGN KEY') {
                    $fks[(string)$c->COLUMN_NAME] = [
                        'table' => (string)$c->REFERENCED_TABLE_NAME,
                        'col'   => (string)($c->REFERENCED_COLUMN_NAME ?: 'id'),
                    ];
                }
            }

            // Construire meta simple
            [$fields, $casts] = $this->columnsToFieldsAndCasts($cols);
            $maybePivot = $this->isPivotTableGuess($table, $fields, $fks);
            if ($skipPivots && $maybePivot) {
                $this->line("↷ Ignoré (pivot présumé) : {$table}");
                continue;
            }

            $metas[$table] = [
                'table'  => $table,
                'fields' => $fields,
                'casts'  => $casts,
                'fks'    => $fks,
            ];
        }

        return $metas;
    }

    // ---------------------------------------------------------------------
    // Collecte depuis les migrations
    // ---------------------------------------------------------------------
    private function collectFromMigrations(string $dir, array $only, array $except, bool $skipPivots): array
    {
        if (!File::exists($dir)) {
            $this->error("Dossier migrations introuvable: {$dir}");
            return [];
        }

        $files = collect(File::allFiles($dir))
            ->filter(fn($f) => str_ends_with($f->getFilename(), '.php'))
            ->sortBy(fn($f) => $f->getFilename());

        $metas = [];
        foreach ($files as $file) {
            $code = File::get($file->getRealPath()) ?? '';
            if (!preg_match('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $code, $tm)) continue;
            $table = $tm[1];
            if ($this->filtered($table, $only, $except)) continue;

            $skip = ['id','created_at','updated_at','deleted_at'];
            $fields = [];
            $casts  = [];
            $fks    = [];

            // Colonnes
            $pattern = '/\$table->([a-zA-Z_]+)\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*([0-9]+))?\s*\)([^;]*);/m';
            if (preg_match_all($pattern, $code, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    [, $method, $col, $argLen, $chain] = $match + [null,null,null,null,null];
                    if (in_array($col, $skip, true)) continue;

                    $type = $this->methodToSqlType(strtolower($method));
                    $fields[] = $col;
                    $casts[$col] = $this->sqlTypeToCast($type);

                    if ($method === 'foreignId') {
                        $refTable = $this->extractConstrainedTable($chain) ?: Str::plural(Str::beforeLast($col, '_id'));
                        $fks[$col] = ['table' => $refTable, 'col' => 'id'];
                    }
                }
            }

            $maybePivot = $this->isPivotTableGuess($table, $fields, $fks);
            if ($skipPivots && $maybePivot) {
                $this->line("↷ Ignoré (pivot présumé) : {$table}");
                continue;
            }

            $metas[$table] = [
                'table'  => $table,
                'fields' => array_values(array_unique($fields)),
                'casts'  => $casts,
                'fks'    => $fks,
            ];
        }

        return $metas;
    }

    // ---------------------------------------------------------------------
    // Construction de la collection Postman
    // ---------------------------------------------------------------------
    private function buildCollection(array $tablesMeta, string $baseUrl): array
    {
        $items = [];
        foreach ($tablesMeta as $table => $meta) {
            $slug = str_replace('_', '-', $table);           // chemin API
            $name = Str::studly(Str::singular($table));      // nom « humain »
            $sample = $this->sampleJson($meta['fields'], $meta['casts'], $meta['fks']);

            $items[] = [
                'name' => $name,
                'item' => [
                    [
                        'name' => 'Index',
                        'request' => ['method' => 'GET', 'url' => "{{base_url}}/api/{$slug}"],
                    ],
                    [
                        'name' => 'Store',
                        'request' => [
                            'method' => 'POST',
                            'header' => [['key'=>'Content-Type','value'=>'application/json']],
                            'body'   => ['mode'=>'raw', 'raw'=> json_encode($sample, JSON_PRETTY_PRINT)],
                            'url'    => "{{base_url}}/api/{$slug}",
                        ],
                    ],
                    [
                        'name' => 'Show',
                        'request' => ['method' => 'GET', 'url' => "{{base_url}}/api/{$slug}/1"],
                    ],
                    [
                        'name' => 'Update',
                        'request' => [
                            'method' => 'PUT',
                            'header' => [['key'=>'Content-Type','value'=>'application/json']],
                            'body'   => ['mode'=>'raw', 'raw'=> json_encode($sample, JSON_PRETTY_PRINT)],
                            'url'    => "{{base_url}}/api/{$slug}/1",
                        ],
                    ],
                    [
                        'name' => 'Destroy',
                        'request' => ['method' => 'DELETE', 'url' => "{{base_url}}/api/{$slug}/1"],
                    ],
                ],
            ];
        }

        return [
            'info' => [
                'name' => 'API Global Collection',
                '_postman_id' => (string) Str::uuid(),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => [
                ['key' => 'base_url', 'value' => $baseUrl],
            ],
            'item' => $items,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------
    private function csvToArray(string $csv): array
    {
        if (!trim($csv)) return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    private function filtered(string $table, array $only, array $except): bool
    {
        if (!empty($only) && !in_array($table, $only, true)) return true;
        if (!empty($except) && in_array($table, $except, true)) return true;
        // ignorer tables Laravel communes
        if (in_array($table, ['migrations','password_reset_tokens','failed_jobs','personal_access_tokens','jobs','job_batches'], true)) return true;
        return false;
    }

    private function columnsToFieldsAndCasts(array $cols): array
    {
        $skip = ['id','created_at','updated_at','deleted_at'];
        $fields = [];
        $casts  = [];
        foreach ($cols as $c) {
            $name = (string)$c->COLUMN_NAME;
            if (in_array($name, $skip, true)) continue;
            $type = strtolower((string)$c->DATA_TYPE);
            $ctype = (string)($c->COLUMN_TYPE ?? '');
            $fields[] = $name;
            $casts[$name] = $this->sqlTypeToCast($type);
            // enum mysql => cast string (déjà), pas besoin de plus ici
        }
        return [$fields, $casts];
    }

    private function sqlTypeToCast(string $type): string
    {
        return match (true) {
            str_contains($type, 'int')         => 'integer',
            str_contains($type, 'bool')        => 'boolean',
            in_array($type, ['decimal','numeric','double','float'], true) => 'float',
            in_array($type, ['json','jsonb'], true) => 'array',
            $type === 'date'                   => 'date',
            str_contains($type, 'time') || str_contains($type, 'date') => 'datetime',
            default                            => 'string',
        };
    }

    private function methodToSqlType(string $method): string
    {
        return match ($method) {
            'string','char' => 'varchar',
            'text','mediumtext','longtext' => 'text',
            'integer','tinyinteger','smallinteger','mediuminteger' => 'int',
            'biginteger','foreignid' => 'bigint',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime','datetimetz' => 'datetime',
            'timestamp','timestamptz' => 'timestamp',
            'json','jsonb' => 'json',
            'decimal' => 'decimal',
            'float','double' => 'double',
            'enum' => 'enum',
            default => 'varchar',
        };
    }

    private function extractConstrainedTable(string $chain): ?string
    {
        if (preg_match("/->constrained\\(['\"]([^'\"]+)['\"]\\)/", $chain, $m)) {
            return $m[1];
        }
        return null;
    }

    private function isPivotTableGuess(string $table, array $fields, array $fks): bool
    {
        // Heuristique : pas d'id, que des *_id, 2 FKs minimum, aucune autre colonne "métier"
        $noId = !in_array('id', $fields, true);
        $fkCount = count($fks);
        $nonFk = array_diff($fields, array_keys($fks));
        return $noId && $fkCount >= 2 && count($nonFk) === 0;
    }

    private function sampleJson(array $fields, array $casts, array $fks): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (isset($fks[$f])) { $out[$f] = 1; continue; }
            $cast = $casts[$f] ?? 'string';
            $out[$f] = match ($cast) {
                'integer' => 1,
                'boolean' => true,
                'float'   => 10.5,
                'array'   => [],
                'date'    => '2025-01-01',
                'datetime'=> '2025-01-01 12:00:00',
                default   => 'exemple',
            };
        }
        return $out;
    }
}
