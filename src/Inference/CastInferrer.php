<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class CastInferrer
{
    /** @return array<string,string> */
    public function infer(EntityMetadata $metadata): array
    {
        $casts = [];
        foreach ($metadata->columns as $column) {
            if (($column->primary && $column->autoIncrement)
                || in_array($column->name, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            $cast = match ($column->normalizedType) {
                'boolean' => 'boolean',
                'array' => 'array',
                'date' => 'date',
                'datetime' => 'datetime',
                'integer' => 'integer',
                'float' => 'float',
                'decimal' => $column->scale !== null ? 'decimal:'.$column->scale : 'string',
                'string', 'text', 'enum' => 'string',
                default => null,
            };
            if ($cast !== null) $casts[$column->name] = $cast;
        }
        return $casts;
    }
}
