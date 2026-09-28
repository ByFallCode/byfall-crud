<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class LegacyMetadataAdapter
{
    public static function fromLegacy(array $legacy, string $name = ''): EntityMetadata
    {
        $fields = array_values($legacy['fields'] ?? []);
        $casts = $legacy['casts'] ?? [];
        $foreignKeys = $legacy['foreign_keys'] ?? [];
        $uniqueFields = array_values($legacy['unique_fields'] ?? []);

        $columns = array_map(
            static function (string $field) use ($casts, $foreignKeys, $uniqueFields): ColumnMetadata {
                $foreignKey = isset($foreignKeys[$field])
                    ? new ForeignKeyMetadata(
                        $field,
                        (string) ($foreignKeys[$field]['table'] ?? ''),
                        (string) ($foreignKeys[$field]['col'] ?? 'id'),
                    )
                    : null;

                return new ColumnMetadata(
                    name: $field,
                    normalizedType: (string) ($casts[$field] ?? 'string'),
                    unique: in_array($field, $uniqueFields, true),
                    foreignKey: $foreignKey,
                );
            },
            $fields,
        );

        $uniqueConstraints = array_map(
            static fn (string $field): UniqueConstraintMetadata => new UniqueConstraintMetadata([$field]),
            $uniqueFields,
        );

        return new EntityMetadata(
            name: $name,
            table: (string) ($legacy['table'] ?? ''),
            columns: $columns,
            uniqueConstraints: $uniqueConstraints,
            softDeletes: (bool) ($legacy['soft_deletes'] ?? false),
            fillable: $fields,
            casts: $casts,
            storeRules: $legacy['rules_store'] ?? [],
            updateRules: $legacy['rules_update'] ?? [],
        );
    }

    public static function toLegacy(EntityMetadata $metadata): array
    {
        $foreignKeys = [];
        foreach ($metadata->columns as $column) {
            if ($column->foreignKey !== null) {
                $foreignKeys[$column->name] = [
                    'table' => $column->foreignKey->referencedTable,
                    'col' => $column->foreignKey->referencedColumn,
                ];
            }
        }

        $uniqueFields = [];
        foreach ($metadata->uniqueConstraints as $constraint) {
            if (count($constraint->columns) === 1) {
                $uniqueFields[] = $constraint->columns[0];
            }
        }

        return [
            'table' => $metadata->table,
            'fields' => $metadata->fillable,
            'casts' => $metadata->casts,
            'rules_store' => $metadata->storeRules,
            'rules_update' => $metadata->updateRules,
            'unique_fields' => $uniqueFields,
            'foreign_keys' => $foreignKeys,
            'soft_deletes' => $metadata->softDeletes,
        ];
    }
}
