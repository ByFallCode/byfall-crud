<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Inference;

use ByfallCode\ByfallCrud\Inference\ValidationRuleInferrer;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;
use PHPUnit\Framework\TestCase;

final class ValidationRuleInferrerTest extends TestCase
{
    public function test_defaults_nullable_unique_and_foreign_keys_are_inferred_from_facts(): void
    {
        $metadata = new EntityMetadata('Product', 'products', columns: [
            new ColumnMetadata('name', normalizedType: 'string', length: 100),
            new ColumnMetadata('active', normalizedType: 'boolean', defaultValue: true, hasDefault: true),
            new ColumnMetadata('summary', normalizedType: 'string', nullable: true, hasDefault: true),
            new ColumnMetadata('email', normalizedType: 'string'),
            new ColumnMetadata('category_code', normalizedType: 'string', foreignKey: new ForeignKeyMetadata('category_code', 'categories', 'code')),
        ], uniqueConstraints: [new UniqueConstraintMetadata(['email'])]);

        $result = (new ValidationRuleInferrer())->infer($metadata, ['name', 'active', 'summary', 'email', 'category_code']);
        self::assertSame('required|string|max:100', $result['store']['name']);
        self::assertSame('sometimes|boolean', $result['store']['active']);
        self::assertSame('sometimes|nullable|string', $result['store']['summary']);
        self::assertSame('required|string|unique:products,email', $result['store']['email']);
        self::assertSame('required|string|exists:categories,code', $result['store']['category_code']);
        self::assertSame('sometimes|string', $result['update']['email']);
    }

    public function test_composite_unique_is_preserved_without_false_field_rules(): void
    {
        $metadata = new EntityMetadata('Article', 'articles', columns: [
            new ColumnMetadata('tenant_id', normalizedType: 'integer'),
            new ColumnMetadata('slug', normalizedType: 'string'),
        ], uniqueConstraints: [new UniqueConstraintMetadata(['tenant_id', 'slug'], 'tenant_slug_unique')]);
        $result = (new ValidationRuleInferrer())->infer($metadata, ['tenant_id', 'slug']);

        self::assertStringNotContainsString('unique:', $result['store']['tenant_id']);
        self::assertStringNotContainsString('unique:', $result['store']['slug']);
        self::assertSame('COMPOSITE_UNIQUE_REQUIRES_CONTEXT', $result['diagnostics'][0]->code);
    }
}
