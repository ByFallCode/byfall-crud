<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ByfallCode\ByfallCrud\Metadata\LegacyMetadataAdapter;

final class LegacyMetadataAdapterTest extends TestCase
{
    public function test_legacy_metadata_round_trip_is_lossless(): void
    {
        $legacy = [
            'table' => 'products',
            'fields' => ['category_id', 'name', 'active'],
            'casts' => ['category_id' => 'integer', 'name' => 'string', 'active' => 'boolean'],
            'rules_store' => ['category_id' => 'required|integer|exists:categories,id', 'name' => 'required|string'],
            'rules_update' => ['category_id' => 'sometimes|integer|exists:categories,id', 'name' => 'sometimes|string'],
            'unique_fields' => ['name'],
            'foreign_keys' => ['category_id' => ['table' => 'categories', 'col' => 'id']],
            'soft_deletes' => true,
        ];

        $metadata = LegacyMetadataAdapter::fromLegacy($legacy, 'Product');

        self::assertSame('Product', $metadata->name);
        self::assertSame($legacy, LegacyMetadataAdapter::toLegacy($metadata));
    }
}
