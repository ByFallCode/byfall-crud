<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Installation;

final class InstallationResult
{
    /** @param array<string,string> $files */
    public function __construct(
        public readonly array $files,
        public readonly string $integration,
        public readonly bool $complete,
    ) {}
}
