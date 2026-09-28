<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class MetadataEnricher
{
    public function __construct(
        private readonly CastInferrer $casts = new CastInferrer(),
        private readonly FillableInferrer $fillable = new FillableInferrer(),
        private readonly HiddenFieldInferrer $hidden = new HiddenFieldInferrer(),
        private readonly ValidationRuleInferrer $validation = new ValidationRuleInferrer(),
        private readonly RelationshipInferrer $relationships = new RelationshipInferrer(),
        private readonly QueryCapabilityInferrer $queries = new QueryCapabilityInferrer(),
    ) {}

    public function enrich(EntityMetadata $raw): EntityMetadata
    {
        $fillable = $this->fillable->infer($raw);
        $relationshipResult = $this->relationships->infer($raw);
        $validationResult = $this->validation->infer($raw, $fillable);
        $queryResult = $this->queries->infer($raw, $relationshipResult['relationships']);

        return $raw->withEnrichment(
            relationships: $relationshipResult['relationships'],
            fillable: $fillable,
            hidden: $this->hidden->infer($raw),
            casts: $this->casts->infer($raw),
            storeRules: $validationResult['store'],
            updateRules: $validationResult['update'],
            searchable: $queryResult['searchable'],
            filterable: $queryResult['filterable'],
            sortable: $queryResult['sortable'],
            allowedIncludes: $queryResult['allowedIncludes'],
            diagnostics: [...$validationResult['diagnostics'], ...$relationshipResult['diagnostics']],
        );
    }
}
