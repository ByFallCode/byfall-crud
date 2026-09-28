<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\InferenceDiagnostic;

final class ValidationRuleInferrer
{
    /** @return array{store:array<string,string>,update:array<string,string>,diagnostics:list<InferenceDiagnostic>} */
    public function infer(EntityMetadata $metadata, array $fillable): array
    {
        $store = [];
        $update = [];
        $diagnostics = [];
        $simpleUnique = [];
        foreach ($metadata->uniqueConstraints as $constraint) {
            if (count($constraint->columns) === 1) {
                $simpleUnique[] = $constraint->columns[0];
            } else {
                $diagnostics[] = new InferenceDiagnostic(
                    'warning',
                    'COMPOSITE_UNIQUE_REQUIRES_CONTEXT',
                    'Composite uniqueness is preserved but no potentially false field-level rule was generated.',
                    ['constraint' => $constraint->name, 'columns' => $constraint->columns],
                );
            }
        }

        foreach ($metadata->columns as $column) {
            if (!in_array($column->name, $fillable, true)) continue;
            $storeParts = [$column->hasDefault ? 'sometimes' : ($column->nullable ? 'nullable' : 'required')];
            $updateParts = ['sometimes'];
            if ($column->nullable) {
                if (!in_array('nullable', $storeParts, true)) $storeParts[] = 'nullable';
                $updateParts[] = 'nullable';
            }
            $typeRule = match ($column->normalizedType) {
                'integer' => 'integer', 'boolean' => 'boolean', 'decimal', 'float' => 'numeric',
                'array' => 'array', 'date', 'datetime' => 'date', default => 'string',
            };
            $storeParts[] = $typeRule;
            $updateParts[] = $typeRule;
            if ($typeRule === 'string' && $column->length !== null) {
                $storeParts[] = 'max:'.$column->length;
                $updateParts[] = 'max:'.$column->length;
            }
            if ($column->foreignKey !== null) {
                $exists = 'exists:'.$column->foreignKey->referencedTable.','.$column->foreignKey->referencedColumn;
                $storeParts[] = $exists;
                $updateParts[] = $exists;
            }
            if (in_array($column->name, $simpleUnique, true)) {
                $storeParts[] = 'unique:'.$metadata->table.','.$column->name;
            }
            $store[$column->name] = implode('|', $storeParts);
            $update[$column->name] = implode('|', $updateParts);
        }
        return compact('store', 'update', 'diagnostics');
    }
}
