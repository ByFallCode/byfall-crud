<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\ResourceGenerator;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\RelationshipMetadata;
use PHPUnit\Framework\TestCase;

final class ResourceGeneratorTest extends TestCase
{
    public function test_it_exposes_columns_except_hidden_and_uses_when_loaded(): void
    {
        $columns = array_map(static fn (string $name): ColumnMetadata => new ColumnMetadata($name), [
            'id', 'name', 'password', 'remember_token', 'api_token', 'secret', 'created_at', 'updated_at',
        ]);
        $metadata = new EntityMetadata(
            'User', 'users', columns: $columns,
            relationships: [
                new RelationshipMetadata('category', 'belongsTo', 'Category', 'category_id'),
                new RelationshipMetadata('unsafe', 'belongsTo', 'Team', 'team_ref', ambiguous: true, confidence: 0.6),
            ],
            hidden: ['password', 'remember_token', 'api_token', 'secret'],
        );

        $code = (new ResourceGenerator())->generate($metadata, new GenerationContext('User'));

        self::assertStringContainsString("'id' => \$this->id", $code);
        self::assertStringContainsString("'name' => \$this->name", $code);
        self::assertStringContainsString("'created_at' => \$this->created_at", $code);
        self::assertStringNotContainsString("'password' =>", $code);
        self::assertStringNotContainsString("'remember_token' =>", $code);
        self::assertStringNotContainsString("'api_token' =>", $code);
        self::assertStringNotContainsString("'secret' =>", $code);
        self::assertStringContainsString("'category' => \$this->whenLoaded('category')", $code);
        self::assertStringNotContainsString("\$this->category,", $code);
        self::assertStringNotContainsString("'unsafe' =>", $code);
    }
}
