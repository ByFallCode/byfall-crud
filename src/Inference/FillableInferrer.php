<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class FillableInferrer
{
    /** @return list<string> */
    public function infer(EntityMetadata $metadata): array
    {
        $fillable = [];
        foreach ($metadata->columns as $column) {
            if ($column->generated || ($column->primary && $column->autoIncrement)) continue;
            if (in_array($column->name, ['created_at', 'updated_at', 'deleted_at'], true)) continue;
            $fillable[] = $column->name;
        }
        return $fillable;
    }
}
