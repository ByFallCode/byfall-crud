<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Contracts;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

interface SchemaSource
{
    public function inspect(string $identifier, string $entityName = ''): EntityMetadata;
}
