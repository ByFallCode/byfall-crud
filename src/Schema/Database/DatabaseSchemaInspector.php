<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema\Database;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

interface DatabaseSchemaInspector
{
    public function inspect(string $table, string $entityName = ''): EntityMetadata;

    /** @return list<string> */
    public function tables(): array;
}
