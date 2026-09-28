<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema\Database;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class PostgreSqlSchemaInspector extends AbstractDatabaseSchemaInspector
{
    public function inspect(string $table, string $entityName = ''): EntityMetadata
    {
        $columns = $this->connection->select(<<<SQL
            SELECT column_name, data_type, is_nullable, character_maximum_length,
                   numeric_precision, numeric_scale, column_default,
                   data_type AS column_type, is_generated
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        SQL, [$table]);
        $constraints = $this->connection->select(<<<SQL
            SELECT tc.constraint_name, tc.constraint_type, kcu.column_name,
                   ccu.table_name AS referenced_table_name,
                   ccu.column_name AS referenced_column_name,
                   kcu.ordinal_position
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            LEFT JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            WHERE tc.table_schema = 'public' AND kcu.table_name = ?
            ORDER BY tc.constraint_name, kcu.ordinal_position
        SQL, [$table]);

        return $this->buildMetadata($table, $entityName, $columns, $constraints);
    }

    public function tables(): array
    {
        $rows = $this->connection->select(
            "SELECT tablename AS table_name FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename",
        );
        return array_values(array_map(static fn ($row) => (string) ((array) $row)['table_name'], $rows));
    }
}
