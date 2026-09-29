<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class QuerySpecificationGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $name = $context->entityName;
        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Queries;

final class {$name}QuerySpecification
{
    public function __construct(
        public readonly ?string \$search,
        public readonly array \$filters,
        public readonly ?string \$sort,
        public readonly string \$direction,
        public readonly array \$includes,
        public readonly int \$perPage,
    ) {}

    public function queryParameters(): array
    {
        return array_filter([
            'search' => \$this->search,
            ...\$this->filters,
            'sort' => \$this->sort,
            'direction' => \$this->sort === null ? null : \$this->direction,
            'include' => \$this->includes === [] ? null : implode(',', \$this->includes),
            'per_page' => \$this->perPage,
        ], static fn (mixed \$value): bool => \$value !== null && \$value !== '');
    }
}
PHP;
    }
}
