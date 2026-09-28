<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\InferenceDiagnostic;
use ByfallCode\ByfallCrud\Metadata\RelationshipMetadata;
use Illuminate\Support\Str;

final class RelationshipInferrer
{
    /** @return array{relationships:list<RelationshipMetadata>,diagnostics:list<InferenceDiagnostic>} */
    public function infer(EntityMetadata $metadata): array
    {
        $relationships = [];
        $diagnostics = [];
        foreach ($metadata->columns as $column) {
            $foreignKey = $column->foreignKey;
            if ($foreignKey === null) continue;
            $suffix = '_'.$foreignKey->referencedColumn;
            $conventional = str_ends_with($column->name, $suffix);
            $base = $conventional ? substr($column->name, 0, -strlen($suffix)) : '';
            if ($base === '' && str_ends_with($column->name, '_id')) {
                $base = substr($column->name, 0, -3);
                $conventional = true;
            }
            $ambiguous = $base === '';
            $name = $ambiguous ? Str::camel(Str::singular($foreignKey->referencedTable)) : Str::camel($base);
            if ($ambiguous) {
                $diagnostics[] = new InferenceDiagnostic(
                    'warning', 'AMBIGUOUS_RELATION_NAME',
                    'The relationship name was derived from the referenced table.',
                    ['column' => $column->name, 'relation' => $name],
                );
            }
            $relationships[] = new RelationshipMetadata(
                name: $name,
                type: 'belongsTo',
                relatedModel: Str::studly(Str::singular($foreignKey->referencedTable)),
                foreignKey: $column->name,
                ownerKey: $foreignKey->referencedColumn,
                ambiguous: $ambiguous,
                confidence: $ambiguous ? 0.6 : 1.0,
            );
        }
        return compact('relationships', 'diagnostics');
    }
}
