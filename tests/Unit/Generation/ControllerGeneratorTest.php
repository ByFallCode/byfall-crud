<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\ControllerGenerator;
use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ControllerGeneratorTest extends TestCase
{
    public function test_it_generates_repository_compatible_resource_controller(): void
    {
        $code = (new ControllerGenerator())->generate(
            new EntityMetadata('Product', 'products'),
            new GenerationContext('Product', routeParameter: 'catalog_product'),
        );

        self::assertStringContainsString('use App\\Repositories\\ProductRepository;', $code);
        self::assertStringContainsString('use App\\Queries\\ProductQueryParser;', $code);
        self::assertStringContainsString('use App\\Support\\ApiResponse;', $code);
        self::assertStringContainsString('use App\\Http\\Resources\\ProductResource;', $code);
        self::assertStringContainsString('StoreProductRequest $request', $code);
        self::assertStringContainsString('UpdateProductRequest $request', $code);
        self::assertStringContainsString('$request->validated()', $code);
        self::assertStringNotContainsString('$request->all()', $code);
        self::assertStringContainsString('$specification = (new ProductQueryParser())->parse($request);', $code);
        self::assertStringContainsString('$this->repository->paginate($specification)', $code);
        self::assertStringNotContainsString("query('sort'", $code);
        self::assertStringNotContainsString("query('include'", $code);
        self::assertStringContainsString('new ProductResource($item)', $code);
        self::assertStringContainsString("ApiResponse::created", $code);
        self::assertStringContainsString("ApiResponse::success", $code);
        self::assertStringContainsString("ApiResponse::noContent", $code);
        self::assertStringContainsString('int|string $catalog_product', $code);
        self::assertStringContainsString('$this->repository->update($catalog_product, $request->validated())', $code);
        self::assertStringContainsString('$this->repository->delete($catalog_product)', $code);
    }

    public function test_no_resources_controller_remains_valid_and_does_not_import_resource(): void
    {
        $code = (new ControllerGenerator())->generate(
            new EntityMetadata('Product', 'products'),
            new GenerationContext('Product', routeParameter: 'product', withResources: false),
        );

        self::assertStringNotContainsString('ProductResource', $code);
        self::assertStringContainsString('ApiResponse::created($item', $code);
        self::assertStringContainsString('ApiResponse::success($item', $code);
        $this->assertPhpValid($code);
    }

    public function test_generator_has_no_schema_or_installation_responsibilities(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Generation/ControllerGenerator.php');
        foreach (['information_schema', 'pg_catalog', 'Schema::', 'MigrationSchemaSource', 'SensitiveFieldPolicy', 'File::put'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    private function assertPhpValid(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'byfall-controller-');
        file_put_contents($file, $code);
        try {
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        } finally {
            @unlink($file);
        }
    }
}
