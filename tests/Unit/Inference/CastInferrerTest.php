<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Inference;

use ByfallCode\ByfallCrud\Inference\CastInferrer;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use PHPUnit\Framework\TestCase;

final class CastInferrerTest extends TestCase
{
    public function test_it_infers_safe_laravel_casts_without_losing_decimal_precision(): void
    {
        $metadata = new EntityMetadata('Metric', 'metrics', columns: [
            new ColumnMetadata('enabled', normalizedType: 'boolean'),
            new ColumnMetadata('payload', normalizedType: 'array'),
            new ColumnMetadata('published_on', normalizedType: 'date'),
            new ColumnMetadata('published_at', normalizedType: 'datetime'),
            new ColumnMetadata('views', normalizedType: 'integer'),
            new ColumnMetadata('price', normalizedType: 'decimal', precision: 12, scale: 4),
            new ColumnMetadata('unscaled_amount', normalizedType: 'decimal'),
        ]);

        self::assertSame([
            'enabled' => 'boolean', 'payload' => 'array', 'published_on' => 'date',
            'published_at' => 'datetime', 'views' => 'integer', 'price' => 'decimal:4',
            'unscaled_amount' => 'string',
        ], (new CastInferrer())->infer($metadata));
    }
}
