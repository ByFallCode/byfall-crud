<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Inference;

use ByfallCode\ByfallCrud\Inference\FillableInferrer;
use ByfallCode\ByfallCrud\Inference\HiddenFieldInferrer;
use ByfallCode\ByfallCrud\Inference\SensitiveFieldPolicy;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FillableAndHiddenInferrerTest extends TestCase
{
    public function test_fillable_excludes_generated_and_managed_columns_but_keeps_password(): void
    {
        $metadata = new EntityMetadata('User', 'users', columns: [
            new ColumnMetadata('id', autoIncrement: true, primary: true),
            new ColumnMetadata('email'), new ColumnMetadata('password'),
            new ColumnMetadata('computed_secret', generated: true),
            new ColumnMetadata('created_at'), new ColumnMetadata('updated_at'), new ColumnMetadata('deleted_at'),
        ]);
        self::assertSame(['email', 'password'], (new FillableInferrer())->infer($metadata));
    }

    #[DataProvider('sensitiveFields')]
    public function test_sensitive_fields_are_hidden_and_never_overmatch_generic_keys(string $field): void
    {
        $policy = new SensitiveFieldPolicy();
        self::assertTrue($policy->isSensitive($field));
        $metadata = new EntityMetadata('User', 'users', columns: [new ColumnMetadata($field)]);
        self::assertSame([$field], (new HiddenFieldInferrer($policy))->infer($metadata));
        self::assertFalse($policy->isSensitive('foreign_key'));
        self::assertFalse($policy->isSensitive('public_key_id'));
    }

    public static function sensitiveFields(): array
    {
        return array_map(static fn ($field) => [$field], [
            'password', 'password_hash', 'remember_token', 'api_token', 'access_token',
            'refresh_token', 'secret', 'secret_key', 'private_key', 'two_factor_secret',
            'two_factor_recovery_codes',
        ]);
    }

    public function test_a_generated_secret_remains_hidden_but_is_not_fillable(): void
    {
        $metadata = new EntityMetadata('Credential', 'credentials', columns: [
            new ColumnMetadata('secret', generated: true),
        ]);
        self::assertSame(['secret'], (new HiddenFieldInferrer())->infer($metadata));
        self::assertSame([], (new FillableInferrer())->infer($metadata));
    }
}
