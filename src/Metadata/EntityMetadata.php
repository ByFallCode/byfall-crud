<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class EntityMetadata
{
    /**
     * @param list<ColumnMetadata> $columns
     * @param list<object> $relationships
     * @param list<IndexMetadata> $indexes
     * @param list<UniqueConstraintMetadata> $uniqueConstraints
     * @param list<string> $fillable
     * @param list<string> $hidden
     * @param array<string,string> $casts
     * @param array<string,string> $storeRules
     * @param array<string,string> $updateRules
     * @param list<string> $searchable
     * @param list<string> $filterable
     * @param list<string> $sortable
     * @param list<string> $allowedIncludes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $primaryKey = 'id',
        public readonly array $columns = [],
        public readonly array $relationships = [],
        public readonly array $indexes = [],
        public readonly array $uniqueConstraints = [],
        public readonly bool $timestamps = true,
        public readonly bool $softDeletes = false,
        public readonly array $fillable = [],
        public readonly array $hidden = [],
        public readonly array $casts = [],
        public readonly array $storeRules = [],
        public readonly array $updateRules = [],
        public readonly array $searchable = [],
        public readonly array $filterable = [],
        public readonly array $sortable = [],
        public readonly array $allowedIncludes = [],
    ) {}
}
