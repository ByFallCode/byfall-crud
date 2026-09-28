<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema\Database;

use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\IndexMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;
use ByfallCode\ByfallCrud\Support\TypeMapper;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

abstract class AbstractDatabaseSchemaInspector implements DatabaseSchemaInspector
{
    public function __construct(protected readonly Connection $connection) {}

    /**
     * @param list<object> $columnRows
     * @param list<object> $constraintRows
     */
    protected function buildMetadata(string $table, string $entityName, array $columnRows, array $constraintRows): EntityMetadata
    {
        /** @var array<string, list<string>> $constraintColumns */
        $constraintColumns = [];
        /** @var array<string, string> $constraintTypes */
        $constraintTypes = [];
        /** @var array<string, ForeignKeyMetadata> $foreignKeys */
        $foreignKeys = [];

        foreach ($constraintRows as $rowObject) {
            $row = array_change_key_case((array) $rowObject, CASE_LOWER);
            $constraintName = (string) ($row['constraint_name'] ?? '');
            $constraintType = strtoupper((string) ($row['constraint_type'] ?? ''));
            $column = (string) ($row['column_name'] ?? '');
            if ($constraintName !== '' && $column !== '') {
                $constraintColumns[$constraintName][] = $column;
                $constraintTypes[$constraintName] = $constraintType;
            }
            if ($constraintType === 'FOREIGN KEY' && $column !== '') {
                $foreignKeys[$column] = new ForeignKeyMetadata(
                    column: $column,
                    referencedTable: (string) ($row['referenced_table_name'] ?? ''),
                    referencedColumn: (string) ($row['referenced_column_name'] ?? 'id'),
                    constraintName: $constraintName !== '' ? $constraintName : null,
                );
            }
        }

        $uniqueConstraints = [];
        $indexes = [];
        $uniqueColumns = [];
        $primaryColumns = [];
        foreach ($constraintColumns as $constraintName => $columns) {
            $columns = array_values(array_unique($columns));
            $type = $constraintTypes[$constraintName];
            if ($type === 'UNIQUE') {
                $uniqueConstraints[] = new UniqueConstraintMetadata($columns, $constraintName);
                if (count($columns) === 1) $uniqueColumns[] = $columns[0];
                $indexes[] = new IndexMetadata($columns, $constraintName, unique: true);
            } elseif ($type === 'PRIMARY KEY') {
                $primaryColumns = $columns;
                $indexes[] = new IndexMetadata($columns, $constraintName, unique: true, primary: true);
            }
        }

        $columns = [];
        $softDeletes = false;
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        foreach ($columnRows as $rowObject) {
            $row = array_change_key_case((array) $rowObject, CASE_LOWER);
            $name = (string) $row['column_name'];
            $databaseType = strtolower((string) $row['data_type']);
            $columnType = strtolower((string) ($row['column_type'] ?? $databaseType));
            $defaultPresent = array_key_exists('column_default', $row) && $row['column_default'] !== null;
            $defaultValue = $row['column_default'] ?? null;
            $primary = in_array($name, $primaryColumns, true) || strtoupper((string) ($row['column_key'] ?? '')) === 'PRI';
            $unique = in_array($name, $uniqueColumns, true) || strtoupper((string) ($row['column_key'] ?? '')) === 'UNI';
            $extra = strtolower((string) ($row['extra'] ?? ''));
            $column = new ColumnMetadata(
                name: $name,
                databaseType: $databaseType,
                normalizedType: TypeMapper::sqlTypeToNormalizedType($databaseType),
                nullable: strtoupper((string) ($row['is_nullable'] ?? 'NO')) === 'YES',
                defaultValue: $defaultValue,
                hasDefault: $defaultPresent,
                length: isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
                precision: isset($row['numeric_precision']) ? (int) $row['numeric_precision'] : null,
                scale: isset($row['numeric_scale']) ? (int) $row['numeric_scale'] : null,
                unsigned: str_contains($columnType, 'unsigned'),
                autoIncrement: str_contains($extra, 'auto_increment') || str_contains((string) $defaultValue, 'nextval('),
                primary: $primary,
                unique: $unique,
                foreignKey: $foreignKeys[$name] ?? null,
                generated: str_contains($extra, 'generated') || (($row['is_generated'] ?? 'NEVER') !== 'NEVER'),
            );
            $columns[] = $column;
            if ($name === 'deleted_at') $softDeletes = true;
            if ($name === 'created_at') $hasCreatedAt = true;
            if ($name === 'updated_at') $hasUpdatedAt = true;
        }

        $primaryKey = $primaryColumns[0] ?? null;
        if ($primaryKey === null) {
            foreach ($columns as $column) if ($column->primary) { $primaryKey = $column->name; break; }
        }

        return new EntityMetadata(
            name: $entityName !== '' ? $entityName : Str::studly(Str::singular($table)),
            table: $table,
            primaryKey: $primaryKey,
            columns: $columns,
            indexes: $indexes,
            uniqueConstraints: $uniqueConstraints,
            timestamps: $hasCreatedAt && $hasUpdatedAt,
            softDeletes: $softDeletes,
        );
    }
}
