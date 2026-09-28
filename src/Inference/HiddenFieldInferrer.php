<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class HiddenFieldInferrer
{
    public function __construct(private readonly SensitiveFieldPolicy $policy = new SensitiveFieldPolicy()) {}

    /** @return list<string> */
    public function infer(EntityMetadata $metadata): array
    {
        return array_values(array_map(
            static fn ($column) => $column->name,
            array_filter($metadata->columns, fn ($column) => $this->policy->isSensitive($column->name)),
        ));
    }
}
