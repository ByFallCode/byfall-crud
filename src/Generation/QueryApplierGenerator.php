<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Generation\Support\PhpArrayRenderer;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class QueryApplierGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $name = $context->entityName;
        $searchable = PhpArrayRenderer::values($metadata->searchable, 4);
        $defaultSort = $metadata->primaryKey !== null && in_array($metadata->primaryKey, $metadata->sortable, true)
            ? var_export($metadata->primaryKey, true)
            : 'null';
        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;

final class {$name}QueryApplier
{
    private const SEARCHABLE = {$searchable};
    private const DEFAULT_SORT = {$defaultSort};

    public function apply(Builder \$query, {$name}QuerySpecification \$specification): Builder
    {
        if (\$specification->search !== null && self::SEARCHABLE !== []) {
            \$query->where(function (Builder \$searchQuery) use (\$specification): void {
                foreach (self::SEARCHABLE as \$index => \$column) {
                    \$method = \$index === 0 ? 'where' : 'orWhere';
                    \$searchQuery->{\$method}(\$column, 'like', '%'.\$specification->search.'%');
                }
            });
        }
        foreach (\$specification->filters as \$column => \$value) {
            \$query->where(\$column, '=', \$value);
        }
        if (\$specification->includes !== []) \$query->with(\$specification->includes);
        if (\$specification->sort !== null) {
            \$query->orderBy(\$specification->sort, \$specification->direction);
        } elseif (self::DEFAULT_SORT !== null) {
            \$query->orderBy(self::DEFAULT_SORT, 'desc');
        }
        return \$query;
    }
}
PHP;
    }
}
