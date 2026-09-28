<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema;

use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;

/**
 * Temporary compatibility bridge for the historical generators.
 *
 * Schema facts remain in Metadata; a future dedicated inference layer may replace
 * this class without changing SchemaSource or EntitySchemaAnalyzer.
 */
final class LegacyRuleBuilder
{
    /** @param list<string> $enumValues @return array{string,string} */
    public static function forColumn(string $table, ColumnMetadata $column, array $enumValues = []): array
    {
        $baseStore = $column->nullable ? 'nullable' : 'required';
        $baseUpdate = $column->nullable ? 'nullable' : 'sometimes';
        $type = $column->databaseType;
        $tail = match (true) {
            $type === 'enum' => 'in:'.implode(',', array_map(static fn ($value) => str_replace(',', '\\,', $value), $enumValues)),
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'bool') => 'boolean',
            in_array($type, ['decimal', 'numeric', 'double', 'float'], true) => 'numeric',
            in_array($type, ['json', 'jsonb'], true) => 'array',
            $type === 'date' => 'date',
            str_contains($type, 'time') || str_contains($type, 'date') => 'date',
            default => 'string',
        };

        $extra = [];
        if ($tail === 'string' && $column->length !== null) $extra[] = 'max:'.$column->length;
        if ($column->foreignKey !== null) {
            $extra[] = 'exists:'.$column->foreignKey->referencedTable.','.$column->foreignKey->referencedColumn;
        }
        $storeExtra = $extra;
        if ($column->unique) $storeExtra[] = 'unique:'.$table.','.$column->name;

        return [
            $baseStore.'|'.$tail.($storeExtra === [] ? '' : '|'.implode('|', $storeExtra)),
            $baseUpdate.'|'.$tail.($extra === [] ? '' : '|'.implode('|', $extra)),
        ];
    }
}
