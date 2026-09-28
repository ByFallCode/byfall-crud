<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation\Contracts;

use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

interface Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string;
}
