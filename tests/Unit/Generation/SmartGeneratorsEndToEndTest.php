<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\ModelGenerator;
use ByfallCode\ByfallCrud\Generation\ResourceGenerator;
use ByfallCode\ByfallCrud\Generation\StoreRequestGenerator;
use ByfallCode\ByfallCrud\Generation\UpdateRequestGenerator;
use ByfallCode\ByfallCrud\Inference\MetadataEnricher;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;
use PHPUnit\Framework\TestCase;

final class SmartGeneratorsEndToEndTest extends TestCase
{
    public function test_user_metadata_generates_secure_laravel_artifacts(): void
    {
        $metadata = $this->enrich(new EntityMetadata('User', 'users', columns: [
            new ColumnMetadata('id', normalizedType: 'integer', autoIncrement: true, primary: true),
            new ColumnMetadata('name', normalizedType: 'string', length: 100),
            new ColumnMetadata('email', normalizedType: 'string', length: 255, unique: true),
            new ColumnMetadata('password', normalizedType: 'string', length: 255),
            new ColumnMetadata('remember_token', normalizedType: 'string', nullable: true),
            new ColumnMetadata('active', normalizedType: 'boolean', defaultValue: true, hasDefault: true),
            new ColumnMetadata('created_at', normalizedType: 'datetime', nullable: true),
            new ColumnMetadata('updated_at', normalizedType: 'datetime', nullable: true),
        ], uniqueConstraints: [new UniqueConstraintMetadata(['email'])]));
        $artifacts = $this->generateAll($metadata, new GenerationContext('User', routeParameter: 'user'));

        self::assertStringContainsString("protected \$hidden", $artifacts['model']);
        self::assertStringContainsString("'password',", $artifacts['model']);
        self::assertStringContainsString("'active' => 'boolean'", $artifacts['model']);
        self::assertStringContainsString('unique:users,email', $artifacts['store']);
        self::assertStringContainsString("Rule::unique('users', 'email')", $artifacts['update']);
        self::assertStringNotContainsString("'password' =>", $artifacts['resource']);
        self::assertStringNotContainsString("'remember_token' =>", $artifacts['resource']);
    }

    public function test_product_metadata_generates_relational_precise_artifacts(): void
    {
        $metadata = $this->enrich(new EntityMetadata('Product', 'products', columns: [
            new ColumnMetadata('id', normalizedType: 'integer', autoIncrement: true, primary: true),
            new ColumnMetadata('category_id', normalizedType: 'integer', foreignKey: new ForeignKeyMetadata('category_id', 'categories')),
            new ColumnMetadata('name', normalizedType: 'string', length: 255),
            new ColumnMetadata('description', normalizedType: 'text', nullable: true),
            new ColumnMetadata('price', normalizedType: 'decimal', precision: 10, scale: 2),
            new ColumnMetadata('active', normalizedType: 'boolean', defaultValue: true, hasDefault: true),
            new ColumnMetadata('created_at', normalizedType: 'datetime', nullable: true),
            new ColumnMetadata('updated_at', normalizedType: 'datetime', nullable: true),
        ]));
        $artifacts = $this->generateAll($metadata, new GenerationContext('Product', routeParameter: 'product'));

        self::assertStringContainsString("'price' => 'decimal:2'", $artifacts['model']);
        self::assertStringContainsString('public function category(): BelongsTo', $artifacts['model']);
        self::assertStringContainsString("Category::class, 'category_id', 'id'", $artifacts['model']);
        self::assertStringContainsString("'category_id' => ['required', 'integer', 'exists:categories,id']", $artifacts['store']);
        self::assertStringContainsString("'active' => ['sometimes', 'boolean']", $artifacts['store']);
        self::assertStringContainsString("'category' => \$this->whenLoaded('category')", $artifacts['resource']);
    }

    private function enrich(EntityMetadata $metadata): EntityMetadata
    {
        return (new MetadataEnricher())->enrich($metadata);
    }

    /** @return array{model:string,store:string,update:string,resource:string} */
    private function generateAll(EntityMetadata $metadata, GenerationContext $context): array
    {
        return [
            'model' => (new ModelGenerator())->generate($metadata, $context),
            'store' => (new StoreRequestGenerator())->generate($metadata, $context),
            'update' => (new UpdateRequestGenerator())->generate($metadata, $context),
            'resource' => (new ResourceGenerator())->generate($metadata, $context),
        ];
    }
}
