<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation\Support;

final class PhpArrayRenderer
{
    /** @param list<string> $values */
    public static function values(array $values, int $indent = 4): string
    {
        if ($values === []) return '[]';
        $padding = str_repeat(' ', $indent + 4);
        return "[\n".implode("\n", array_map(
            static fn (string $value): string => $padding.var_export($value, true).',',
            $values,
        ))."\n".str_repeat(' ', $indent).']';
    }

    /** @param array<string,string> $values */
    public static function associative(array $values, int $indent = 4): string
    {
        if ($values === []) return '[]';
        $padding = str_repeat(' ', $indent + 4);
        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $padding.var_export($key, true).' => '.var_export($value, true).',';
        }
        return "[\n".implode("\n", $lines)."\n".str_repeat(' ', $indent).']';
    }
}
