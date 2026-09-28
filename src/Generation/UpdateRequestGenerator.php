<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class UpdateRequestGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $class = 'Update'.$context->entityName.'Request';
        $namespace = $context->requestNamespace.'\\'.$context->entityName;
        $hasSafeUnique = $context->routeParameter !== null && $this->simpleUniqueFields($metadata) !== [];
        $ruleUse = $hasSafeUnique ? "use Illuminate\\Validation\\Rule;\n" : '';
        $rules = $this->renderRules($metadata, $context);
        return "<?php\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
            ."use Illuminate\\Foundation\\Http\\FormRequest;\n{$ruleUse}\n"
            ."class {$class} extends FormRequest\n{\n"
            ."    public function authorize(): bool\n    {\n        return true;\n    }\n\n"
            ."    public function rules(): array\n    {\n        return {$rules};\n    }\n}\n";
    }

    private function renderRules(EntityMetadata $metadata, GenerationContext $context): string
    {
        if ($metadata->updateRules === []) return '[]';
        $unique = $this->simpleUniqueFields($metadata);
        $lines = [];
        foreach ($metadata->updateRules as $field => $pipe) {
            $parts = array_values(array_filter(explode('|', $pipe), static fn (string $part): bool => $part !== '' && !str_starts_with($part, 'unique:')));
            $values = array_map(static fn (string $part): string => var_export($part, true), $parts);
            if ($context->routeParameter !== null && in_array($field, $unique, true)) {
                $values[] = 'Rule::unique('.var_export($metadata->table, true).', '.var_export($field, true).')'
                    .'->ignore($this->route('.var_export($context->routeParameter, true).'))';
            }
            $lines[] = '            '.var_export($field, true).' => ['.implode(', ', $values).'],';
        }
        return "[\n".implode("\n", $lines)."\n        ]";
    }

    /** @return list<string> */
    private function simpleUniqueFields(EntityMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->uniqueConstraints as $constraint) {
            if (count($constraint->columns) === 1) $fields[] = $constraint->columns[0];
        }
        return $fields;
    }
}
