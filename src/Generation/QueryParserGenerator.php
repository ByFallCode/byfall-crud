<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Generation\Support\PhpArrayRenderer;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class QueryParserGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $name = $context->entityName;
        $reservedParameters = ['search', 'sort', 'direction', 'include', 'per_page', 'page'];
        $filterableFields = array_values(array_diff($metadata->filterable, $reservedParameters));
        $booleanFilters = [];
        foreach ($metadata->columns as $column) {
            if ($column->normalizedType === 'boolean' && in_array($column->name, $filterableFields, true)) {
                $booleanFilters[] = $column->name;
            }
        }
        $filterable = PhpArrayRenderer::values($filterableFields, 4);
        $sortable = PhpArrayRenderer::values($metadata->sortable, 4);
        $includes = PhpArrayRenderer::values($metadata->allowedIncludes, 4);
        $booleans = PhpArrayRenderer::values($booleanFilters, 4);

        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Queries;

use Illuminate\Http\Request;

final class {$name}QueryParser
{
    private const FILTERABLE = {$filterable};
    private const SORTABLE = {$sortable};
    private const ALLOWED_INCLUDES = {$includes};
    private const BOOLEAN_FILTERS = {$booleans};

    public function parse(Request \$request): {$name}QuerySpecification
    {
        \$searchValue = \$request->query('search', '');
        \$search = is_scalar(\$searchValue) ? trim((string) \$searchValue) : '';
        \$filters = [];
        foreach (self::FILTERABLE as \$field) {
            if (!\$request->query->has(\$field)) continue;
            \$value = \$request->query(\$field);
            if (is_array(\$value)) continue;
            if (in_array(\$field, self::BOOLEAN_FILTERS, true)) {
                \$value = filter_var(\$value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (\$value === null) continue;
            }
            \$filters[\$field] = \$value;
        }

        \$sortValue = \$request->query('sort', '');
        \$requestedSort = is_scalar(\$sortValue) ? (string) \$sortValue : '';
        \$sort = in_array(\$requestedSort, self::SORTABLE, true) ? \$requestedSort : null;
        \$directionValue = \$request->query('direction', 'asc');
        \$direction = is_scalar(\$directionValue) ? strtolower((string) \$directionValue) : 'asc';
        if (!in_array(\$direction, ['asc', 'desc'], true)) \$direction = 'asc';

        \$includeValue = \$request->query('include', '');
        \$includeList = is_scalar(\$includeValue) ? (string) \$includeValue : '';
        \$requestedIncludes = array_unique(array_filter(array_map(
            'trim', explode(',', \$includeList),
        )));
        \$includes = array_values(array_intersect(\$requestedIncludes, self::ALLOWED_INCLUDES));

        \$requestedPerPage = filter_var(\$request->query('per_page', 15), FILTER_VALIDATE_INT);
        \$perPage = \$requestedPerPage === false ? 15 : max(1, min(100, \$requestedPerPage));

        return new {$name}QuerySpecification(
            \$search === '' ? null : \$search,
            \$filters,
            \$sort,
            \$direction,
            \$includes,
            \$perPage,
        );
    }
}
PHP;
    }
}
