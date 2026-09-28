<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema\Database;

use ByfallCode\ByfallCrud\Contracts\SchemaSource;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use Illuminate\Database\Connection;

final class DatabaseSchemaSource implements SchemaSource
{
    private DatabaseSchemaInspector $inspector;

    public function __construct(private readonly Connection $connection)
    {
        $this->inspector = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => new MySqlSchemaInspector($connection),
            'pgsql', 'postgres', 'postgresql' => new PostgreSqlSchemaInspector($connection),
            default => throw new \RuntimeException('Driver non supporté : '.$connection->getDriverName()),
        };
    }

    public function inspect(string $identifier, string $entityName = ''): EntityMetadata
    {
        return $this->inspector->inspect($identifier, $entityName);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->inspector->tables();
    }
}
