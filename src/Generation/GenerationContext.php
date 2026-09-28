<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

final class GenerationContext
{
    public function __construct(
        public readonly string $entityName,
        public readonly string $modelNamespace = 'App\\Models',
        public readonly string $requestNamespace = 'App\\Http\\Requests',
        public readonly string $resourceNamespace = 'App\\Http\\Resources',
        public readonly ?string $routeParameter = null,
        public readonly bool $withResources = true,
    ) {}
}
