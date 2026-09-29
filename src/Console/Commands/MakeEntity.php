<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Console\Commands;

use ByfallCode\ByfallCrud\Metadata\LegacyMetadataAdapter;
use ByfallCode\ByfallCrud\Inference\MetadataEnricher;
use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\ControllerGenerator;
use ByfallCode\ByfallCrud\Generation\ModelGenerator;
use ByfallCode\ByfallCrud\Generation\QueryApplierGenerator;
use ByfallCode\ByfallCrud\Generation\QueryParserGenerator;
use ByfallCode\ByfallCrud\Generation\QuerySpecificationGenerator;
use ByfallCode\ByfallCrud\Generation\RepositoryGenerator;
use ByfallCode\ByfallCrud\Generation\ResourceGenerator;
use ByfallCode\ByfallCrud\Generation\StoreRequestGenerator;
use ByfallCode\ByfallCrud\Generation\Support\SafeFileWriter;
use ByfallCode\ByfallCrud\Generation\UpdateRequestGenerator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Schema\Database\DatabaseSchemaSource;
use ByfallCode\ByfallCrud\Schema\EntitySchemaAnalyzer;
use ByfallCode\ByfallCrud\Schema\Migration\MigrationSchemaSource;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
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
            $analyzer = new EntitySchemaAnalyzer();
            if ($source === 'db') {
                $metadata = (new MetadataEnricher())->enrich($analyzer->analyze(new DatabaseSchemaSource(DB::connection()), $table, $name));
                $this->info("📦 Inférence DB réussie : {$table}");
            } elseif ($source === 'migration') {
                $migration = (string) $this->option('migration');
                if (!$migration || !File::exists($migration)) {
                    $this->error('--migration est requis et doit exister.');
                    return self::FAILURE;
                }
                $metadata = (new MetadataEnricher())->enrich($analyzer->analyze(new MigrationSchemaSource(app(Filesystem::class)), $migration, $name));
                $this->info("Inférence migration OK: table={$metadata->table}");
            } else {
                $this->error("--source doit être 'db' ou 'migration'.");
                return self::FAILURE;
            }
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($metadata->fillable === []) {
            $this->error('Aucune colonne déduite.');
            return self::FAILURE;
        }

        $meta = LegacyMetadataAdapter::toLegacy($metadata);
        $withResources = !$this->option('no-resources');
        $context = new GenerationContext(
            entityName: $name,
            routeParameter: Str::snake(Str::singular($name)),
            withResources: $withResources,
        );

        // ---------- Génération ----------
        $this->generateSmartArtifacts($metadata, $context, $force, $withResources);
        $this->generateRepository($metadata, $context, $force);
        $this->generateController($metadata, $context, $force, $withResources);

        if ($withResources) {
            $this->generateResourceCollection($name, $force);
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
    // ==================   GÉNÉRATION CODE   ==================
    // =========================================================

    private function generateSmartArtifacts(
        EntityMetadata $metadata,
        GenerationContext $context,
        bool $force,
        bool $withResource,
    ): void
    {
        $writer = new SafeFileWriter();
        $this->writeSmartArtifact(
            $writer,
            app_path("Models/{$context->entityName}.php"),
            (new ModelGenerator())->generate($metadata, $context),
            $force,
            "Model : app/Models/{$context->entityName}.php",
        );
        $requestDirectory = app_path("Http/Requests/{$context->entityName}");
        $this->writeSmartArtifact(
            $writer,
            $requestDirectory."/Store{$context->entityName}Request.php",
            (new StoreRequestGenerator())->generate($metadata, $context),
            $force,
            "StoreRequest : app/Http/Requests/{$context->entityName}/Store{$context->entityName}Request.php",
        );
        $this->writeSmartArtifact(
            $writer,
            $requestDirectory."/Update{$context->entityName}Request.php",
            (new UpdateRequestGenerator())->generate($metadata, $context),
            $force,
            "UpdateRequest : app/Http/Requests/{$context->entityName}/Update{$context->entityName}Request.php",
        );
        if ($withResource) {
            $this->writeSmartArtifact(
                $writer,
                app_path("Http/Resources/{$context->entityName}Resource.php"),
                (new ResourceGenerator())->generate($metadata, $context),
                $force,
                "Resource : app/Http/Resources/{$context->entityName}Resource.php",
            );
        }
    }

    private function writeSmartArtifact(
        SafeFileWriter $writer,
        string $path,
        string $content,
        bool $force,
        string $label,
    ): void {
        if ($writer->write($path, $content, $force)) {
            $this->info("✅ {$label}");
        } else {
            $this->warn("⚠️ Fichier existe déjà: {$label}");
        }
    }

    private function generateRepository(EntityMetadata $metadata, GenerationContext $context, bool $force): void
    {
        if (!File::exists(app_path('Support/ApiResponse.php'))) {
            $this->generateLegacyRepository($context->entityName, $force);
            return;
        }

        $writer = new SafeFileWriter();
        $artifacts = [
            ["Queries/{$context->entityName}QuerySpecification.php", new QuerySpecificationGenerator(), 'Query specification'],
            ["Queries/{$context->entityName}QueryParser.php", new QueryParserGenerator(), 'Query parser'],
            ["Queries/{$context->entityName}QueryApplier.php", new QueryApplierGenerator(), 'Query applier'],
            ["Repositories/{$context->entityName}Repository.php", new RepositoryGenerator(), 'Repository'],
        ];
        foreach ($artifacts as [$relative, $generator, $label]) {
            $this->writeSmartArtifact(
                $writer,
                app_path($relative),
                $generator->generate($metadata, $context),
                $force,
                "{$label} : app/{$relative}",
            );
        }
    }

    private function generateLegacyRepository(string $name, bool $force): void
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

    private function generateController(
        EntityMetadata $metadata,
        GenerationContext $context,
        bool $force,
        bool $withResources,
    ): void
    {
        if (!File::exists(app_path('Support/ApiResponse.php'))) {
            $this->warn('API Foundation absente; Controller legacy généré. Exécute php artisan byfall:install pour activer le Smart Controller.');
            $this->generateLegacyController($context->entityName, $force, $withResources);
            return;
        }

        $this->writeSmartArtifact(
            new SafeFileWriter(),
            app_path("Http/Controllers/{$context->entityName}Controller.php"),
            (new ControllerGenerator())->generate($metadata, $context),
            $force,
            "Controller : app/Http/Controllers/{$context->entityName}Controller.php",
        );
    }

    private function generateLegacyController(string $name, bool $force, bool $withResources): void
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


    private function generateResourceCollection(string $name, bool $force): void
    {
        $rDir = app_path('Http/Resources');
        if (!File::exists($rDir)) File::makeDirectory($rDir, 0755, true);

        $colPath = $rDir.DIRECTORY_SEPARATOR.$name.'Collection.php';

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

}
