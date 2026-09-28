<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Inference;

final class SensitiveFieldPolicy
{
    private const EXACT_NAMES = [
        'password', 'password_hash', 'remember_token', 'api_token', 'access_token',
        'refresh_token', 'secret', 'secret_key', 'private_key', 'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function isSensitive(string $field): bool
    {
        $field = strtolower($field);
        return in_array($field, self::EXACT_NAMES, true)
            || str_ends_with($field, '_password')
            || str_ends_with($field, '_token')
            || str_ends_with($field, '_secret');
    }
}
