<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class IndexMetadata
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly array $columns,
        public readonly ?string $name = null,
        public readonly bool $unique = false,
        public readonly bool $primary = false,
    ) {}
}
