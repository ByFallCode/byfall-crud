<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\ControllerGenerator;
use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\ModelGenerator;
use ByfallCode\ByfallCrud\Generation\QueryApplierGenerator;
use ByfallCode\ByfallCrud\Generation\QueryParserGenerator;
use ByfallCode\ByfallCrud\Generation\QuerySpecificationGenerator;
use ByfallCode\ByfallCrud\Generation\RepositoryGenerator;
use ByfallCode\ByfallCrud\Generation\ResourceGenerator;
use ByfallCode\ByfallCrud\Generation\StoreRequestGenerator;
use ByfallCode\ByfallCrud\Generation\UpdateRequestGenerator;
use ByfallCode\ByfallCrud\Inference\MetadataEnricher;
use ByfallCode\ByfallCrud\Installation\ApiFoundationInstaller;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ApiInstallationGenerationIntegrationTest extends TestCase
{
    public function test_installed_foundation_and_product_artifacts_are_coherent(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'byfall-e2e-'.bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->ensureDirectoryExists($base.'/bootstrap');
        $files->put($base.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
        try {
            $installation = (new ApiFoundationInstaller())->install($base);
            $metadata = (new MetadataEnricher())->enrich(new EntityMetadata('Product', 'products', columns: [
                new ColumnMetadata('id', normalizedType: 'integer', primary: true, autoIncrement: true),
                new ColumnMetadata('category_id', normalizedType: 'integer', foreignKey: new ForeignKeyMetadata('category_id', 'categories')),
                new ColumnMetadata('name', normalizedType: 'string', length: 255),
                new ColumnMetadata('description', normalizedType: 'text', nullable: true),
                new ColumnMetadata('price', normalizedType: 'decimal', precision: 10, scale: 2),
                new ColumnMetadata('active', normalizedType: 'boolean', defaultValue: true, hasDefault: true),
                new ColumnMetadata('created_at', normalizedType: 'datetime', nullable: true),
                new ColumnMetadata('updated_at', normalizedType: 'datetime', nullable: true),
            ]));
            $context = new GenerationContext('Product', routeParameter: 'product');
            $artifacts = [
                (new ModelGenerator())->generate($metadata, $context),
                (new StoreRequestGenerator())->generate($metadata, $context),
                (new UpdateRequestGenerator())->generate($metadata, $context),
                (new ResourceGenerator())->generate($metadata, $context),
                (new QuerySpecificationGenerator())->generate($metadata, $context),
                (new QueryParserGenerator())->generate($metadata, $context),
                (new QueryApplierGenerator())->generate($metadata, $context),
                (new RepositoryGenerator())->generate($metadata, $context),
                (new ControllerGenerator())->generate($metadata, $context),
            ];

            self::assertTrue($installation->complete);
            self::assertFileExists($base.'/app/Support/ApiResponse.php');
            self::assertStringContainsString("'price' => 'decimal:2'", $artifacts[0]);
            self::assertStringContainsString('exists:categories,id', $artifacts[1]);
            self::assertStringContainsString("'category_id' => ['sometimes', 'integer', 'exists:categories,id']", $artifacts[2]);
            self::assertStringContainsString("whenLoaded('category')", $artifacts[3]);
            self::assertStringContainsString("private const FILTERABLE = [\n        'id',\n        'category_id',\n        'active',\n        'created_at',\n        'updated_at',\n    ];", $artifacts[5]);
            self::assertStringContainsString("private const ALLOWED_INCLUDES = [\n        'category',\n    ];", $artifacts[5]);
            self::assertStringContainsString('(new ProductQueryApplier())->apply(Product::query(), $specification)', $artifacts[7]);
            self::assertStringContainsString('use App\\Support\\ApiResponse;', $artifacts[8]);
            self::assertStringNotContainsString('ByfallCode\\ByfallCrud', implode("\n", $artifacts));
            foreach ($artifacts as $index => $content) {
                $path = $base.'/artifact-'.$index.'.php';
                $files->put($path, $content);
                $process = new Process([PHP_BINARY, '-l', $path]);
                $process->run();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            }
        } finally {
            $files->deleteDirectory($base);
        }
    }
}
