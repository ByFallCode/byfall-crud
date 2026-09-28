<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class StoreRequestGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $class = 'Store'.$context->entityName.'Request';
        $rules = $this->renderRules($metadata->storeRules);
        $namespace = $context->requestNamespace.'\\'.$context->entityName;
        return "<?php\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
            ."use Illuminate\\Foundation\\Http\\FormRequest;\n\n"
            ."class {$class} extends FormRequest\n{\n"
            ."    public function authorize(): bool\n    {\n        return true;\n    }\n\n"
            ."    public function rules(): array\n    {\n        return {$rules};\n    }\n}\n";
    }

    /** @param array<string,string> $rules */
    private function renderRules(array $rules): string
    {
        if ($rules === []) return '[]';
        $lines = [];
        foreach ($rules as $field => $pipe) {
            $parts = array_values(array_filter(explode('|', $pipe), static fn (string $part): bool => $part !== ''));
            $rendered = implode(', ', array_map(static fn (string $part): string => var_export($part, true), $parts));
            $lines[] = '            '.var_export($field, true).' => ['.$rendered.'],';
        }
        return "[\n".implode("\n", $lines)."\n        ]";
    }
}
