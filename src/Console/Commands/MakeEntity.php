<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Console\Commands;

use ByfallCode\ByfallCrud\Metadata\LegacyMetadataAdapter;
use ByfallCode\ByfallCrud\Support\TypeMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeEntity extends Command
{
    protected $signature = 'make:entity
        {name : Nom d\'entité en StudlyCase, ex: Post}
        {--source=db : db|migration}
        {--table= : Nom de la table (si --source=db). Défaut = snake(plural(name))}
        {--migration= : Chemin du fichier de migration (si --source=migration)}
        {--no-resources : Ne pas générer Resources/Collections}
        {--no-factory : Ne pas générer Factory}
        {--no-seeder : Ne pas générer Seeder}
        {--no-collection-json : Ne pas générer la collection API JSON}
        {--force : Écraser les fichiers existants}
    ';

    protected $description = "Génère Model, Repository, FormRequests, Controller, Resources, Factory, Seeder et une collection API JSON — sans vues.";

    public function handle(): int
    {
        $name   = Str::studly($this->argument('name'));
        $source = $this->option('source') ?: 'db';
        $table  = $this->option('table') ?: Str::snake(Str::pluralStudly($name));
        $force  = (bool) $this->option('force');

        // ---------- Inférence ----------
        try {
            if ($source === 'db') {
                $meta = $this->inferFromDatabase($table);
            } elseif ($source === 'migration') {
                $migration = (string) $this->option('migration');
                if (!$migration || !File::exists($migration)) {
                    $this->error('--migration est requis et doit exister.');
                    return self::FAILURE;
                }
                $meta = $this->inferFromMigration($migration, $table);
            } else {
                $this->error("--source doit être 'db' ou 'migration'.");
                return self::FAILURE;
            }
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if (empty($meta['fields'])) {
            $this->error('Aucune colonne déduite.');
            return self::FAILURE;
        }

        $meta = LegacyMetadataAdapter::toLegacy(LegacyMetadataAdapter::fromLegacy($meta, $name));

        // ---------- Génération ----------
        $withResources = !$this->option('no-resources');
        $this->generateModel($name, $meta, $force);
        $this->generateRepository($name, $force);
        $this->generateFormRequests($name, $meta, $force);
        $this->generateController($name, $force, $withResources);

        if ($withResources) {
            $this->generateApiResources($name, $meta, $force);
        }
        if (!$this->option('no-factory')) {
            $this->generateFactory($name, $meta, $force);
        }
        if (!$this->option('no-seeder')) {
            $this->generateSeeder($name, $force);
        }
        if (!$this->option('no-collection-json')) {
            $this->generateApiCollectionJson($name, $meta, $force);
        }

        $this->line('');
        $kPlural = Str::kebab(Str::pluralStudly($name));
        $this->info("✨ Terminé. Ajoute la route : Route::apiResource('{$kPlural}', \\App\\Http\\Controllers\\{$name}Controller::class);");

        return self::SUCCESS;
    }

    // =========================================================
    // ================   INFÉRENCE DU SCHÉMA   ================
    // =========================================================

    /**
     * Retourne un meta-array:
     * - table: string
     * - fields: string[]
     * - casts:  assoc [col => cast]
     * - rules_store:  assoc [col => pipe_rules  (inclut unique/exists)]
     * - rules_update: assoc [col => pipe_rules  (exclut unique pour gérer ignore($id) dynamiquement)]
     * - unique_fields: string[]
     * - foreign_keys:  assoc [col => ['table'=>..., 'col'=>...]]
     * - soft_deletes:  bool
     */
    private function inferFromDatabase(string $table): array
    {
        $driver = DB::getDriverName();
        $database = DB::getDatabaseName();
        $schema = $driver === 'pgsql' ? 'public' : $database;

        // =========================================================
        // Colonnes
        // =========================================================
        if ($driver === 'mysql') {
            $cols = DB::select(
                <<<SQL
            SELECT
                column_name,
                data_type,
                is_nullable,
                character_maximum_length,
                numeric_precision,
                numeric_scale,
                column_default,
                column_type
            FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = ?
            ORDER BY ordinal_position
            SQL,
                [$database, $table]
            );

            $constraints = DB::select(
                <<<SQL
            SELECT
                tc.constraint_type,
                kcu.column_name,
                kcu.referenced_table_name,
                kcu.referenced_column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            WHERE kcu.table_schema = ?
              AND kcu.table_name = ?
            SQL,
                [$database, $table]
            );

        } elseif ($driver === 'pgsql') {

            $cols = DB::select(
                <<<SQL
            SELECT
                column_name,
                data_type,
                is_nullable,
                character_maximum_length,
                numeric_precision,
                numeric_scale,
                column_default,
                data_type AS column_type
            FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = ?
            ORDER BY ordinal_position
            SQL,
                [$schema, $table]
            );

            $constraints = DB::select(
                <<<SQL
            SELECT
                tc.constraint_type,
                kcu.column_name,
                ccu.table_name   AS referenced_table_name,
                ccu.column_name  AS referenced_column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            LEFT JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.table_schema = tc.table_schema
            WHERE tc.table_schema = ?
              AND kcu.table_name = ?
            SQL,
                [$schema, $table]
            );

        } else {
            throw new \RuntimeException("Driver non supporté : {$driver}");
        }

        // =========================================================
        // Normalisation
        // =========================================================
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at'];

        $fields = [];
        $casts = [];
        $rulesStore = [];
        $rulesUpdate = [];
        $uniqueFields = [];
        $foreignKeys = [];
        $softDeletes = false;

        // ---------- Contraintes ----------
        foreach ($constraints as $c) {
            $c = (array) $c;
            $type = strtoupper((string) ($c['constraint_type'] ?? ''));
            $col  = (string) ($c['column_name'] ?? '');

            if ($type === 'UNIQUE') {
                $uniqueFields[] = $col;
            }

            if ($type === 'FOREIGN KEY') {
                $foreignKeys[$col] = [
                    'table' => (string) ($c['referenced_table_name'] ?? ''),
                    'col'   => (string) ($c['referenced_column_name'] ?? 'id'),
                ];
            }
        }

        // ---------- Colonnes ----------
        foreach ($cols as $r) {
            $r = (array) $r;

            $col = (string) $r['column_name'];
            if ($col === 'deleted_at') {
                $softDeletes = true;
            }
            if (in_array($col, $skip, true)) {
                continue;
            }

            $type     = strtolower((string) $r['data_type']);
            $nullable = strtoupper((string) $r['is_nullable']) === 'YES';
            $len      = $r['character_maximum_length'] ?? null;
            $prec     = $r['numeric_precision'] ?? null;
            $scale    = $r['numeric_scale'] ?? null;
            $ctype    = (string) ($r['column_type'] ?? '');

            $fields[] = $col;
            $casts[$col] = TypeMapper::sqlTypeToCast($type);

            // Enum (MySQL uniquement)
            $enumValues = $this->extractEnumValues($ctype);

            [$store, $update] = $this->sqlMetaToRules(
                $table,
                $col,
                $type,
                $nullable,
                $len,
                $prec,
                $scale,
                in_array($col, $uniqueFields, true),
                $foreignKeys[$col] ?? null,
                $enumValues
            );

            $rulesStore[$col] = $store;
            $rulesUpdate[$col] = $update;
        }

        $this->info("📦 Inférence DB réussie ({$driver}) : {$table}");

        return [
            'table'         => $table,
            'fields'        => $fields,
            'casts'         => $casts,
            'rules_store'   => $rulesStore,
            'rules_update'  => $rulesUpdate,
            'unique_fields' => $uniqueFields,
            'foreign_keys'  => $foreignKeys,
            'soft_deletes'  => $softDeletes,
        ];
    }


    private function inferFromMigration(string $path, string $tableGuess): array
    {
        $code = File::get($path) ?? '';
        $table = $tableGuess;
        if (preg_match('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $code, $tm)) {
            $table = $tm[1];
        }

        $skip = ['id','created_at','updated_at','deleted_at'];
        $fields = [];
        $casts  = [];
        $store  = [];
        $update = [];
        $unique = [];
        $fks    = [];
        $soft   = str_contains($code, 'softDeletes(') || str_contains($code, 'softDeletes()');

        $pattern = '/\$table->([a-zA-Z_]+)\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*([0-9]+))?\s*\)([^;]*);/m';
        if (preg_match_all($pattern, $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                [, $method, $col, $argLen, $chain] = $match + [null,null,null,null,null];
                if (in_array($col, $skip, true)) continue;

                $method   = strtolower($method);
                $nullable = str_contains($chain, 'nullable()');
                $isUnique = str_contains($chain, 'unique()');
                $len      = $argLen ? (int)$argLen : null;
                $type     = TypeMapper::methodToSqlType($method);

                $fields[] = $col;
                $casts[$col] = TypeMapper::sqlTypeToCast($type);
                if ($isUnique) $unique[] = $col;

                if ($method === 'foreignid') {
                    $refTable = $this->extractConstrainedTable($chain) ?: Str::plural(Str::beforeLast($col, '_id'));
                    $fks[$col] = ['table' => $refTable, 'col' => 'id'];
                }

                $enumValues = ($method === 'enum') ? $this->extractEnumValuesFromMethod($chain) : [];

                [$s, $u] = $this->sqlMetaToRules($table, $col, $type, $nullable, $len, null, null, $isUnique, $fks[$col] ?? null, $enumValues);
                $store[$col]  = $s;
                $update[$col] = $u;
            }
        }

        $this->info("Inférence migration OK: table={$table}");

        return [
            'table'         => $table,
            'fields'        => array_values(array_unique($fields)),
            'casts'         => $casts,
            'rules_store'   => $store,
            'rules_update'  => $update,
            'unique_fields' => array_values(array_unique($unique)),
            'foreign_keys'  => $fks,
            'soft_deletes'  => $soft,
        ];
    }

    private function extractEnumValues(string $columnType): array
    {
        if (preg_match("/^enum\\((.*)\\)$/i", $columnType, $m)) {
            preg_match_all("/'([^']+)'/", $m[1], $vals);
            return $vals[1] ?? [];
        }
        return [];
    }
    private function extractEnumValuesFromMethod(string $chain): array
    {
        if (preg_match("/\\[([^\\]]+)\\]/", $chain, $m)) {
            preg_match_all("/'([^']+)'/", $m[1], $vals);
            return $vals[1] ?? [];
        }
        return [];
    }
    private function extractConstrainedTable(string $chain): ?string
    {
        if (preg_match("/->constrained\\(['\"]([^'\"]+)['\"]\\)/", $chain, $m)) {
            return $m[1];
        }
        return null;
    }

    private function sqlMetaToRules(
        string $table,
        string $col,
        string $type,
        bool $nullable,
        ?int $len,
        ?int $prec,
        ?int $scale,
        bool $isUnique = false,
        ?array $fk = null,
        array $enumValues = []
    ): array {
        $baseStore  = $nullable ? 'nullable' : 'required';
        $baseUpdate = $nullable ? 'nullable' : 'sometimes';

        $tail = match (true) {
            $type === 'enum' => 'in:'.implode(',', array_map(fn($v)=>str_replace(',', '\,', $v), $enumValues)),
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'bool') => 'boolean',
            in_array($type, ['decimal','numeric','double','float'], true) => 'numeric',
            in_array($type, ['json','jsonb'], true) => 'array',
            $type === 'date' => 'date',
            str_contains($type, 'time') || str_contains($type, 'date') => 'date',
            default => 'string',
        };

        $extra = [];
        if ($tail === 'string' && $len) $extra[] = "max:{$len}";
        if ($fk)       $extra[] = "exists:{$fk['table']},{$fk['col']}";

        // pour STORE, on inclut unique
        $extraStore = $extra;
        if ($isUnique) $extraStore[] = "unique:{$table},{$col}";

        $storeRules  = $baseStore  . '|' . $tail . (empty($extraStore) ? '' : '|' . implode('|', $extraStore));
        $updateRules = $baseUpdate . '|' . $tail . (empty($extra) ? '' : '|' . implode('|', $extra));

        return [$storeRules, $updateRules];
    }

    // =========================================================
    // ==================   GÉNÉRATION CODE   ==================
    // =========================================================

    private function generateModel(string $name, array $meta, bool $force): void
    {
        $dir  = app_path('Models');
        $path = $dir.DIRECTORY_SEPARATOR.$name.'.php';
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ Model existe déjà: app/Models/{$name}.php");
            return;
        }

        $fillable = $this->exportArray($meta['fields'] ?? []);
        $casts    = $this->exportAssocArray($meta['casts'] ?? []);
        $softUse  = !empty($meta['soft_deletes']) ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
        $soft     = !empty($meta['soft_deletes']) ? "    use SoftDeletes;\n" : '';

        // belongsTo
        $belongsMethods = '';
        if (!empty($meta['foreign_keys'])) {
            foreach ($meta['foreign_keys'] as $fkCol => $fk) {
                $related = Str::studly(Str::singular($fk['table']));
                $method  = Str::camel(Str::beforeLast($fkCol, '_id')) ?: Str::camel($related);
                $belongsMethods .= <<<PHP

    public function {$method}()
    {
        return \$this->belongsTo(\\App\\Models\\{$related}::class, '{$fkCol}');
    }

PHP;
            }
        }

        $tableLine = "    protected \$table = '".($meta['table'] ?? Str::snake(Str::pluralStudly($name)))."';\n";

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;
{$softUse}
class {$name} extends Model
{
    use HasFactory;
{$soft}
{$tableLine}
    protected \$fillable = {$fillable};

    protected \$casts = {$casts};
{$belongsMethods}}
PHP;

        File::put($path, $stub);
        $this->info("✅ Model : app/Models/{$name}.php");
    }

    private function generateRepository(string $name, bool $force): void
    {
        $dir  = app_path('Repositories');
        $path = $dir.DIRECTORY_SEPARATOR.$name.'Repository.php';
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ Repository existe déjà: app/Repositories/{$name}Repository.php");
            return;
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Repositories;

use App\\Models\\{$name};
use Illuminate\\Contracts\\Pagination\\LengthAwarePaginator;
use Illuminate\\Database\\Eloquent\\Collection;

class {$name}Repository
{
    public function paginate(int \$perPage = 15): LengthAwarePaginator
    {
        return {$name}::query()->latest('id')->paginate(\$perPage);
    }

    /** @return Collection<int, {$name}> */
    public function all(): Collection
    {
        return {$name}::query()->latest('id')->get();
    }

    public function find(int|string \$id): {$name}
    {
        return {$name}::query()->findOrFail(\$id);
    }

    public function create(array \$data): {$name}
    {
        return {$name}::query()->create(\$data);
    }

    public function update(int|string \$id, array \$data): {$name}
    {
        \$item = \$this->find(\$id);
        \$item->update(\$data);
        return \$item;
    }

    public function delete(int|string \$id): void
    {
        \$item = \$this->find(\$id);
        \$item->delete();
    }
}

PHP;

        File::put($path, $stub);
        $this->info("✅ Repository : app/Repositories/{$name}Repository.php");
    }

    private function generateFormRequests(string $name, array $meta, bool $force): void
    {
        $dir = app_path('Http/Requests/'.$name);
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);

        $storeRulesStr  = $this->exportAssocArray($meta['rules_store']  ?? []);
        // Update : on gèrera unique(ignore) dans la classe, donc on enlève unique de la chaîne
        $updateRulesBase = $this->stripUniqueFromRules($meta['rules_update'] ?? []);

        $this->writeStoreRequest($name, $storeRulesStr, $force);
        $this->writeUpdateRequest($name, $meta['table'], $updateRulesBase, $meta['unique_fields'] ?? [], $force);
    }

    private function writeStoreRequest(string $name, string $rulesArray, bool $force): void
    {
        $class = "Store{$name}Request";
        $path  = app_path("Http/Requests/{$name}/{$class}.php");
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ FormRequest existe déjà: Http/Requests/{$name}/{$class}.php");
            return;
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Http\\Requests\\{$name};

use Illuminate\\Foundation\\Http\\FormRequest;

class {$class} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return {$rulesArray};
    }
}

PHP;
        File::put($path, $stub);
        $this->info("✅ StoreRequest : app/Http/Requests/{$name}/{$class}.php");
    }

    private function writeUpdateRequest(string $name, string $table, array $rulesBase, array $uniqueFields, bool $force): void
    {
        $class = "Update{$name}Request";
        $path  = app_path("Http/Requests/{$name}/{$class}.php");
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ FormRequest existe déjà: Http/Requests/{$name}/{$class}.php");
            return;
        }

        $param = Str::snake(Str::singular($name)); // ex: 'campagne'
        $lines = [];
        foreach ($rulesBase as $field => $rulePipe) {
            $parts = array_filter(explode('|', $rulePipe));
            $php = implode("','", $parts);
            $arr = empty($php) ? "" : "'{$php}', ";

            if (in_array($field, $uniqueFields, true)) {
                $lines[] = "            '{$field}' => [{$arr}\\Illuminate\\Validation\\Rule::unique('{$table}', '{$field}')->ignore(\$id)],";
            } else {
                $lines[] = "            '{$field}' => [{$arr}],";
            }
        }
        $rulesBody = empty($lines) ? "" : "\n".implode("\n", $lines)."\n        ";

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Http\\Requests\\{$name};

use Illuminate\\Foundation\\Http\\FormRequest;
use Illuminate\\Validation\\Rule;

class {$class} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        \$id = \$this->route('{$param}');
        return [{$rulesBody}];
    }
}

PHP;
        File::put($path, $stub);
        $this->info("✅ UpdateRequest : app/Http/Requests/{$name}/{$class}.php");
    }

    private function stripUniqueFromRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $pipe) {
            $parts = array_filter(explode('|', $pipe), fn($p) => !str_starts_with($p, 'unique:'));
            $out[$field] = implode('|', $parts);
        }
        return $out;
    }

    private function generateController(string $name, bool $force, bool $withResources): void
    {
        $dir  = app_path('Http/Controllers');
        $path = $dir.DIRECTORY_SEPARATOR.$name.'Controller.php';
        if (!\Illuminate\Support\Facades\File::exists($dir)) \Illuminate\Support\Facades\File::makeDirectory($dir, 0755, true);
        if (\Illuminate\Support\Facades\File::exists($path) && !$force) {
            $this->warn("⚠️ Controller existe déjà: app/Http/Controllers/{$name}Controller.php");
            return;
        }

        $param = \Illuminate\Support\Str::camel($name); // ex: Test => test
        $resourceUses = $withResources
            ? "use App\\Http\\Resources\\{$name}Resource;\nuse App\\Http\\Resources\\{$name}Collection;\n"
            : '';
        $indexValue = $withResources
            ? "new {$name}Collection(\$this->repository->paginate(\$perPage))"
            : "\$this->repository->paginate(\$perPage)";
        $itemValue = $withResources ? "new {$name}Resource(\$item)" : '$item';
        $showValue = $withResources
            ? "new {$name}Resource(\$this->repository->find(\${$param}))"
            : "\$this->repository->find(\${$param})";

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Repositories\\{$name}Repository;
use App\Http\Requests\\{$name}\Store{$name}Request;
use App\Http\Requests\\{$name}\Update{$name}Request;
{$resourceUses}use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class {$name}Controller extends Controller
{
    public function __construct(private readonly {$name}Repository \$repository) {}

    public function index(Request \$request): JsonResponse
    {
        \$perPage = (int) (\$request->integer('per_page') ?: 15);
        return response()->json({$indexValue});
    }

    public function store(Store{$name}Request \$request): JsonResponse
    {
        \$item = \$this->repository->create(\$request->validated());
        return response()->json({$itemValue}, 201);
    }

    public function show(int|string \${$param}): JsonResponse
    {
        return response()->json({$showValue});
    }

    public function update(Update{$name}Request \$request, int|string \${$param}): JsonResponse
    {
        \$item = \$this->repository->update(\${$param}, \$request->validated());
        return response()->json({$itemValue});
    }

    public function destroy(int|string \${$param}): JsonResponse
    {
        \$this->repository->delete(\${$param});
        return response()->json(null, 204);
    }
}
PHP;

        $stub .= PHP_EOL;

        \Illuminate\Support\Facades\File::put($path, $stub);
        $this->info("✅ Controller : app/Http/Controllers/{$name}Controller.php");
    }


    private function generateApiResources(string $name, array $meta, bool $force): void
    {
        $rDir = app_path('Http/Resources');
        if (!File::exists($rDir)) File::makeDirectory($rDir, 0755, true);

        $resPath = $rDir.DIRECTORY_SEPARATOR.$name.'Resource.php';
        $colPath = $rDir.DIRECTORY_SEPARATOR.$name.'Collection.php';

        $fields = $meta['fields'] ?? [];
        $arrayBody = empty($fields)
            ? "return parent::toArray(\$request);"
            : "return [\n".implode("\n", array_map(fn($f)=>"            '{$f}' => \$this->{$f},", $fields))."\n        ];";

        if (!File::exists($resPath) || $force) {
            $resStub = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Http\\Resources;

use Illuminate\\Http\\Resources\\Json\\JsonResource;

class {$name}Resource extends JsonResource
{
    public function toArray(\$request): array
    {
        {$arrayBody}
    }
}

PHP;
            File::put($resPath, $resStub);
            $this->info("✅ Resource : app/Http/Resources/{$name}Resource.php");
        } else {
            $this->warn("⚠️ Resource existe déjà: {$name}Resource.php");
        }

        if (!File::exists($colPath) || $force) {
            $colStub = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Http\\Resources;

use Illuminate\\Http\\Resources\\Json\\ResourceCollection;

class {$name}Collection extends ResourceCollection
{
    public \$collects = {$name}Resource::class;

    public function toArray(\$request): array
    {
        return [
            'data' => \$this->collection,
        ];
    }
}

PHP;
            File::put($colPath, $colStub);
            $this->info("✅ ResourceCollection : app/Http/Resources/{$name}Collection.php");
        } else {
            $this->warn("⚠️ Collection existe déjà: {$name}Collection.php");
        }
    }

    private function generateFactory(string $name, array $meta, bool $force): void
    {
        $dir  = base_path('database/factories');
        $path = $dir.DIRECTORY_SEPARATOR.$name.'Factory.php';
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ Factory existe déjà: database/factories/{$name}Factory.php");
            return;
        }

        $blueprint = $this->fakeBlueprint($meta['fields'] ?? [], $meta['casts'] ?? [], $meta['foreign_keys'] ?? []);

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace Database\\Factories;

use App\\Models\\{$name};
use Illuminate\\Database\\Eloquent\\Factories\\Factory;

class {$name}Factory extends Factory
{
    protected \$model = {$name}::class;

    public function definition(): array
    {
        return {$blueprint};
    }
}

PHP;
        File::put($path, $stub);
        $this->info("✅ Factory : database/factories/{$name}Factory.php");
    }

    private function generateSeeder(string $name, bool $force): void
    {
        $dir  = base_path('database/seeders');
        $path = $dir.DIRECTORY_SEPARATOR.$name.'Seeder.php';
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ Seeder existe déjà: database/seeders/{$name}Seeder.php");
            return;
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use App\\Models\\{$name};

class {$name}Seeder extends Seeder
{
    public function run(): void
    {
        {$name}::factory()->count(20)->create();
    }
}

PHP;
        File::put($path, $stub);
        $this->info("✅ Seeder : database/seeders/{$name}Seeder.php");
        $this->line("➡️ Pense à l’ajouter dans DatabaseSeeder: \$this->call({$name}Seeder::class);");
    }

    private function generateApiCollectionJson(string $name, array $meta, bool $force): void
    {
        $slug = Str::kebab(Str::pluralStudly($name));
        $env  = '{{base_url}}';
        $sampleBody = $this->sampleJson($meta['fields'] ?? [], $meta['casts'] ?? [], $meta['foreign_keys'] ?? []);

        $collection = [
            'info' => [
                'name' => "{$name} API",
                '_postman_id' => Str::uuid()->toString(),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [
                ['name' => 'Index',  'request' => ['method'=>'GET',  'url'=> "{$env}/api/{$slug}"]],
                [
                    'name' => 'Store',
                    'request' => [
                        'method'=>'POST',
                        'header' => [['key'=>'Content-Type','value'=>'application/json']],
                        'body' => ['mode'=>'raw','raw'=> json_encode($sampleBody, JSON_PRETTY_PRINT)],
                        'url'=> "{$env}/api/{$slug}"
                    ]
                ],
                ['name' => 'Show',   'request' => ['method'=>'GET',  'url'=> "{$env}/api/{$slug}/1"]],
                [
                    'name' => 'Update',
                    'request' => [
                        'method'=>'PUT',
                        'header' => [['key'=>'Content-Type','value'=>'application/json']],
                        'body' => ['mode'=>'raw','raw'=> json_encode($sampleBody, JSON_PRETTY_PRINT)],
                        'url'=> "{$env}/api/{$slug}/1"
                    ]
                ],
                ['name' => 'Destroy','request' => ['method'=>'DELETE','url'=> "{$env}/api/{$slug}/1"]],
            ],
            'variable' => [['key'=>'base_url','value'=>'http://localhost:8000']],
        ];

        $dir = storage_path('api-collections');
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        $path = $dir.DIRECTORY_SEPARATOR.$name.'_collection.json';
        if (File::exists($path) && !$force) {
            $this->warn("⚠️ Collection API JSON existe déjà: storage/api-collections/{$name}_collection.json");
            return;
        }
        File::put($path, json_encode($collection, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $this->info("✅ Collection API JSON : storage/api-collections/{$name}_collection.json");
    }

    // =========================================================
    // ======================== HELPERS ========================
    // =========================================================

    private function fakeBlueprint(array $fields, array $casts, array $fks): string
    {
        $lines = [];
        foreach ($fields as $f) {
            if (isset($fks[$f])) {
                // FK → par défaut, créer une référence à une factory si elle existe
                $related = Str::studly(Str::singular($fks[$f]['table']));
                $lines[] = "            '{$f}' => fn() => \\App\\Models\\{$related}::factory(),";
                continue;
            }
            $cast = $casts[$f] ?? 'string';
            $lines[] = match ($cast) {
                'integer' => "            '{$f}' => \$this->faker->numberBetween(1, 9999),",
                'boolean' => "            '{$f}' => \$this->faker->boolean(),",
                'float'   => "            '{$f}' => \$this->faker->randomFloat(2, 0, 9999),",
                'array'   => "            '{$f}' => [],",
                'date'    => "            '{$f}' => \$this->faker->date('Y-m-d'),",
                'datetime'=> "            '{$f}' => \$this->faker->dateTime()->format('Y-m-d H:i:s'),",
                default   => "            '{$f}' => \$this->faker->sentence(),",
            };
        }
        $body = empty($lines) ? "[]" : "[\n".implode("\n", $lines)."\n        ]";
        return $body;
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

    private function exportArray(array $values): string
    {
        if (empty($values)) return '[]';
        $items = array_map(fn($v) => "'".$v."'", $values);
        return '['.implode(', ', $items).']';
    }

    /** @param array<string,string> $assoc */
    private function exportAssocArray(array $assoc): string
    {
        if (empty($assoc)) return '[]';
        $lines = [];
        foreach ($assoc as $k => $v) {
            $lines[] = "        '{$k}' => '{$v}',";
        }
        return "[\n".implode("\n", $lines)."\n    ]";
    }
}
