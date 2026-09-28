<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class UniqueConstraintMetadata
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly array $columns,
        public readonly ?string $name = null,
    ) {}
}
