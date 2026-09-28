<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ByfallCode\ByfallCrud\Support\TypeMapper;

final class TypeMapperTest extends TestCase
{
    #[DataProvider('blueprintTypes')]
    public function test_it_preserves_historical_blueprint_mappings(string $method, string $sqlType): void
    {
        self::assertSame($sqlType, TypeMapper::methodToSqlType($method));
    }

    public static function blueprintTypes(): array
    {
        return [
            ['string', 'varchar'],
            ['foreignId', 'bigint'],
            ['boolean', 'boolean'],
            ['json', 'json'],
            ['decimal', 'decimal'],
            ['unknown', 'varchar'],
        ];
    }

    #[DataProvider('sqlCasts')]
    public function test_it_preserves_historical_cast_mappings(string $type, string $cast): void
    {
        self::assertSame($cast, TypeMapper::sqlTypeToCast($type));
    }

    public static function sqlCasts(): array
    {
        return [
            ['enum', 'string'],
            ['bigint', 'integer'],
            ['boolean', 'boolean'],
            ['decimal', 'float'],
            ['jsonb', 'array'],
            ['date', 'date'],
            ['timestamp', 'datetime'],
            ['varchar', 'string'],
        ];
    }
}
