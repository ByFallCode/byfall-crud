<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class QueryCapabilityInferrer
{
    public function __construct(private readonly SensitiveFieldPolicy $policy = new SensitiveFieldPolicy()) {}

    /** @return array{searchable:list<string>,filterable:list<string>,sortable:list<string>,allowedIncludes:list<string>} */
    public function infer(EntityMetadata $metadata, array $relationships): array
    {
        $searchable = [];
        $filterable = [];
        $sortable = [];
        foreach ($metadata->columns as $column) {
            if ($column->generated || $this->policy->isSensitive($column->name)) continue;
            if (in_array($column->normalizedType, ['string', 'text'], true)) $searchable[] = $column->name;
            if ($column->foreignKey !== null
                || $column->primary
                || in_array($column->normalizedType, ['boolean', 'enum', 'date', 'datetime'], true)
                || in_array($column->name, ['status', 'state', 'type'], true)) {
                $filterable[] = $column->name;
            }
            $simpleName = in_array($column->name, ['name', 'title', 'slug', 'created_at', 'updated_at'], true);
            $scalar = in_array($column->normalizedType, ['integer', 'decimal', 'float', 'date', 'datetime'], true);
            if ($column->primary || $simpleName || $scalar) $sortable[] = $column->name;
        }
        $allowedIncludes = [];
        foreach ($relationships as $relationship) {
            if (!$relationship->ambiguous && $relationship->confidence >= 0.9) $allowedIncludes[] = $relationship->name;
        }
        return compact('searchable', 'filterable', 'sortable', 'allowedIncludes');
    }
}
