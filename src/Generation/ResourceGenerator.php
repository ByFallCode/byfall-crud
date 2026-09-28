<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class ResourceGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $lines = [];
        foreach ($metadata->columns as $column) {
            if (!in_array($column->name, $metadata->hidden, true)) {
                $lines[] = "            '{$column->name}' => \$this->{$column->name},";
            }
        }
        foreach ($metadata->relationships as $relationship) {
            if ($relationship->type === 'belongsTo' && !$relationship->ambiguous && $relationship->confidence >= 1.0) {
                $lines[] = "            '{$relationship->name}' => \$this->whenLoaded('{$relationship->name}'),";
            }
        }
        $body = $lines === [] ? 'return parent::toArray($request);' : "return [\n".implode("\n", $lines)."\n        ];";
        return "<?php\ndeclare(strict_types=1);\n\nnamespace {$context->resourceNamespace};\n\n"
            ."use Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\n"
            ."class {$context->entityName}Resource extends JsonResource\n{\n"
            ."    public function toArray(Request \$request): array\n    {\n        {$body}\n    }\n}\n";
    }
}
