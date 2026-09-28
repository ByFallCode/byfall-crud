<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Metadata;

final class InferenceDiagnostic
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = [],
    ) {}
}
