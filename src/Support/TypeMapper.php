<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Support;

final class TypeMapper
{
    public static function methodToSqlType(string $method): string
    {
        return match (strtolower($method)) {
            'string', 'char' => 'varchar',
            'text', 'mediumtext', 'longtext' => 'text',
            'integer', 'tinyinteger', 'smallinteger', 'mediuminteger' => 'int',
            'biginteger', 'foreignid' => 'bigint',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime', 'datetimetz' => 'datetime',
            'timestamp', 'timestamptz' => 'timestamp',
            'json', 'jsonb' => 'json',
            'decimal' => 'decimal',
            'float', 'double' => 'double',
            'enum' => 'enum',
            default => 'varchar',
        };
    }

    public static function sqlTypeToCast(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            $type === 'enum' => 'string',
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'bool') => 'boolean',
            in_array($type, ['decimal', 'numeric', 'double', 'float'], true) => 'float',
            in_array($type, ['json', 'jsonb'], true) => 'array',
            $type === 'date' => 'date',
            str_contains($type, 'time') || str_contains($type, 'date') => 'datetime',
            default => 'string',
        };
    }

    public static function sqlTypeToNormalizedType(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            $type === 'enum' => 'enum',
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'bool') => 'boolean',
            in_array($type, ['decimal', 'numeric'], true) => 'decimal',
            in_array($type, ['double', 'float', 'real'], true) => 'float',
            in_array($type, ['json', 'jsonb'], true) => 'array',
            $type === 'date' => 'date',
            str_contains($type, 'time') || str_contains($type, 'date') => 'datetime',
            str_contains($type, 'text') => 'text',
            str_contains($type, 'binary') || str_contains($type, 'blob') => 'binary',
            default => 'string',
        };
    }
}
