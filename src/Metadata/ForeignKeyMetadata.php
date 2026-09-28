<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class ForeignKeyMetadata
{
    public function __construct(
        public readonly string $column,
        public readonly string $referencedTable,
        public readonly string $referencedColumn = 'id',
    ) {}
}
