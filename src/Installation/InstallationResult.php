<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Installation;

final class InstallationResult
{
    /**
     * @param array<string,string> $files
     * @param list<string> $legacyArtifacts
     */
    public function __construct(
        public readonly array $files,
        public readonly array $legacyArtifacts = [],
        public readonly bool $legacyProviderRegistered = false,
    ) {}

    public function hasLegacyFoundation(): bool
    {
        return $this->legacyArtifacts !== [] || $this->legacyProviderRegistered;
    }
}
