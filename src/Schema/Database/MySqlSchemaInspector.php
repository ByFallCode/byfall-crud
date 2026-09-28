<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema\Database;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class MySqlSchemaInspector extends AbstractDatabaseSchemaInspector
{
    public function inspect(string $table, string $entityName = ''): EntityMetadata
    {
        $database = $this->connection->getDatabaseName();
        $columns = $this->connection->select(<<<SQL
            SELECT column_name, data_type, is_nullable, character_maximum_length,
                   numeric_precision, numeric_scale, column_default, column_type,
                   column_key, extra, generation_expression,
                   CASE WHEN generation_expression <> '' THEN 'ALWAYS' ELSE 'NEVER' END AS is_generated
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position
        SQL, [$database, $table]);
        $constraints = $this->connection->select(<<<SQL
            SELECT tc.constraint_name, tc.constraint_type, kcu.column_name,
                   kcu.referenced_table_name, kcu.referenced_column_name,
                   kcu.ordinal_position
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
             AND tc.table_name = kcu.table_name
            WHERE tc.table_schema = ? AND tc.table_name = ?
            ORDER BY tc.constraint_name, kcu.ordinal_position
        SQL, [$database, $table]);

        return $this->buildMetadata($table, $entityName, $columns, $constraints);
    }

    public function tables(): array
    {
        $rows = $this->connection->select(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
            [$this->connection->getDatabaseName()],
        );
        return array_values(array_map(static fn ($row) => (string) ((array) $row)['table_name'], $rows));
    }
}
