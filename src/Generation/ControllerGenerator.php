<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use Illuminate\Support\Str;

final class ControllerGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $name = $context->entityName;
        $parameter = $context->routeParameter ?? Str::camel($name);
        $plural = Str::plural($name);
        $storeRequestUse = "use {$context->requestNamespace}\\{$name}\\Store{$name}Request;";
        $updateRequestUse = "use {$context->requestNamespace}\\{$name}\\Update{$name}Request;";
        $repositoryUse = "use App\\Repositories\\{$name}Repository;";
        $queryParserUse = "use App\\Queries\\{$name}QueryParser;";
        $resourceUse = $context->withResources ? "use {$context->resourceNamespace}\\{$name}Resource;\n" : '';
        $resourceItem = static fn (string $value): string => $context->withResources
            ? "new {$name}Resource({$value})"
            : $value;
        $indexTransform = $context->withResources
            ? "        \$paginator->setCollection(\$paginator->getCollection()->map(\n"
                ."            static fn (\$item) => new {$name}Resource(\$item),\n        ));\n"
            : '';
        $createdItem = $resourceItem('$item');
        $responseItem = $resourceItem('$item');

        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

{$storeRequestUse}
{$updateRequestUse}
{$resourceUse}{$repositoryUse}
{$queryParserUse}
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class {$name}Controller extends Controller
{
    public function __construct(private readonly {$name}Repository \$repository) {}

    public function index(Request \$request): JsonResponse
    {
        \$specification = (new {$name}QueryParser())->parse(\$request);
        \$paginator = \$this->repository->paginate(\$specification);
{$indexTransform}        return ApiResponse::paginated(\$paginator, '{$plural} retrieved successfully.');
    }

    public function store(Store{$name}Request \$request): JsonResponse
    {
        \$item = \$this->repository->create(\$request->validated());
        return ApiResponse::created({$createdItem}, '{$name} created successfully.');
    }

    public function show(int|string \${$parameter}): JsonResponse
    {
        \$item = \$this->repository->find(\${$parameter});
        return ApiResponse::success({$responseItem}, '{$name} retrieved successfully.');
    }

    public function update(Update{$name}Request \$request, int|string \${$parameter}): JsonResponse
    {
        \$item = \$this->repository->update(\${$parameter}, \$request->validated());
        return ApiResponse::success({$responseItem}, '{$name} updated successfully.');
    }

    public function destroy(int|string \${$parameter}): Response
    {
        \$this->repository->delete(\${$parameter});
        return ApiResponse::noContent();
    }
}
PHP;
    }
}
