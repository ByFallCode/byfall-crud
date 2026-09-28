<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Generation\Support\PhpArrayRenderer;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class ModelGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $uses = [
            'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;',
            'use Illuminate\\Database\\Eloquent\\Model;',
        ];
        if ($metadata->softDeletes) $uses[] = 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
        if ($this->reliableRelationships($metadata) !== []) {
            $uses[] = 'use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;';
        }

        $traits = '    use HasFactory;';
        if ($metadata->softDeletes) $traits .= "\n    use SoftDeletes;";

        $properties = ["    protected \$table = ".var_export($metadata->table, true).';'];
        if ($metadata->primaryKey !== null && $metadata->primaryKey !== 'id') {
            $properties[] = "    protected \$primaryKey = ".var_export($metadata->primaryKey, true).';';
        }
        $primaryColumn = $this->primaryColumn($metadata);
        if ($primaryColumn !== null && !$primaryColumn->autoIncrement) {
            $properties[] = '    public $incrementing = false;';
            if (in_array($primaryColumn->normalizedType, ['string', 'text'], true)) {
                $properties[] = "    protected \$keyType = 'string';";
            }
        }
        if (!$metadata->timestamps) $properties[] = '    public $timestamps = false;';
        $properties[] = "    protected \$fillable = ".PhpArrayRenderer::values($metadata->fillable).';';
        if ($metadata->hidden !== []) {
            $properties[] = "    protected \$hidden = ".PhpArrayRenderer::values($metadata->hidden).';';
        }

        $casts = '';
        if ($metadata->casts !== []) {
            $casts = "\n\n    protected function casts(): array\n    {\n        return ".PhpArrayRenderer::associative($metadata->casts, 8).";\n    }";
        }

        $relations = '';
        foreach ($this->reliableRelationships($metadata) as $relationship) {
            $relations .= "\n\n    public function {$relationship->name}(): BelongsTo\n    {\n";
            $relations .= "        return \$this->belongsTo(\\{$context->modelNamespace}\\{$relationship->relatedModel}::class, ";
            $relations .= var_export($relationship->foreignKey, true).', '.var_export($relationship->ownerKey, true).');';
            $relations .= "\n    }";
        }

        return "<?php\ndeclare(strict_types=1);\n\nnamespace {$context->modelNamespace};\n\n"
            .implode("\n", $uses)."\n\nclass {$context->entityName} extends Model\n{\n{$traits}\n\n"
            .implode("\n\n", $properties).$casts.$relations."\n}\n";
    }

    private function primaryColumn(EntityMetadata $metadata): ?\ByfallCode\ByfallCrud\Metadata\ColumnMetadata
    {
        foreach ($metadata->columns as $column) {
            if ($column->primary || $column->name === $metadata->primaryKey) return $column;
        }
        return null;
    }

    private function reliableRelationships(EntityMetadata $metadata): array
    {
        return array_values(array_filter(
            $metadata->relationships,
            static fn ($relationship): bool => $relationship->type === 'belongsTo'
                && !$relationship->ambiguous
                && $relationship->confidence >= 1.0,
        ));
    }
}
