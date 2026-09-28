<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class EntityMetadata
{
    /**
     * @param list<ColumnMetadata> $columns
     * @param list<RelationshipMetadata> $relationships
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
     * @param list<InferenceDiagnostic> $diagnostics
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly ?string $primaryKey = 'id',
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
        public readonly array $diagnostics = [],
    ) {}

    /**
     * Return a new enriched value while preserving every observed schema fact.
     *
     * @param list<RelationshipMetadata> $relationships
     * @param list<string> $fillable
     * @param list<string> $hidden
     * @param array<string,string> $casts
     * @param array<string,string> $storeRules
     * @param array<string,string> $updateRules
     * @param list<string> $searchable
     * @param list<string> $filterable
     * @param list<string> $sortable
     * @param list<string> $allowedIncludes
     * @param list<InferenceDiagnostic> $diagnostics
     */
    public function withEnrichment(
        array $relationships,
        array $fillable,
        array $hidden,
        array $casts,
        array $storeRules,
        array $updateRules,
        array $searchable,
        array $filterable,
        array $sortable,
        array $allowedIncludes,
        array $diagnostics = [],
    ): self {
        return new self(
            name: $this->name,
            table: $this->table,
            primaryKey: $this->primaryKey,
            columns: $this->columns,
            relationships: $relationships,
            indexes: $this->indexes,
            uniqueConstraints: $this->uniqueConstraints,
            timestamps: $this->timestamps,
            softDeletes: $this->softDeletes,
            fillable: $fillable,
            hidden: $hidden,
            casts: $casts,
            storeRules: $storeRules,
            updateRules: $updateRules,
            searchable: $searchable,
            filterable: $filterable,
            sortable: $sortable,
            allowedIncludes: $allowedIncludes,
            diagnostics: $diagnostics,
        );
    }
}
