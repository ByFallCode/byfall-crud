<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class RelationshipMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $relatedModel,
        public readonly string $foreignKey,
        public readonly string $ownerKey = 'id',
        public readonly ?string $localKey = null,
        public readonly ?string $pivotTable = null,
        public readonly ?string $pivotForeignKey = null,
        public readonly ?string $pivotRelatedKey = null,
        public readonly bool $ambiguous = false,
        public readonly float $confidence = 1.0,
    ) {}
}
