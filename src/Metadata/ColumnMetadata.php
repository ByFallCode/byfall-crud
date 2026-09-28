<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class ColumnMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly string $databaseType = '',
        public readonly string $normalizedType = 'string',
        public readonly bool $nullable = false,
        public readonly mixed $defaultValue = null,
        public readonly bool $hasDefault = false,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $unsigned = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $primary = false,
        public readonly bool $unique = false,
        public readonly ?ForeignKeyMetadata $foreignKey = null,
        public readonly bool $generated = false,
    ) {}
}
