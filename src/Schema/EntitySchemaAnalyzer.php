<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema;

use ByfallCode\ByfallCrud\Contracts\SchemaSource;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class EntitySchemaAnalyzer
{
    /** @var array<string, EntityMetadata> */
    private array $cache = [];

    public function analyze(SchemaSource $source, string $identifier, string $entityName = ''): EntityMetadata
    {
        $key = hash('sha256', serialize([spl_object_id($source), $identifier, $entityName]));

        return $this->cache[$key] ??= $source->inspect($identifier, $entityName);
    }

    public function inspectionCount(): int
    {
        return count($this->cache);
    }
}
