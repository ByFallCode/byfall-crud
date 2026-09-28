<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\StoreRequestGenerator;
use ByfallCode\ByfallCrud\Generation\UpdateRequestGenerator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;
use PHPUnit\Framework\TestCase;

final class RequestGeneratorsTest extends TestCase
{
    public function test_store_and_update_render_only_metadata_rules(): void
    {
        $metadata = new EntityMetadata(
            'User', 'users',
            uniqueConstraints: [new UniqueConstraintMetadata(['email'])],
            storeRules: [
                'email' => 'required|string|max:255|unique:users,email',
                'active' => 'sometimes|boolean',
                'birthday' => 'nullable|date',
                'roles' => 'sometimes|array',
            ],
            updateRules: [
                'email' => 'sometimes|string|max:255',
                'active' => 'sometimes|boolean',
            ],
        );
        $context = new GenerationContext('User', routeParameter: 'user');

        $store = (new StoreRequestGenerator())->generate($metadata, $context);
        $update = (new UpdateRequestGenerator())->generate($metadata, $context);

        self::assertStringContainsString("'email' => ['required', 'string', 'max:255', 'unique:users,email']", $store);
        self::assertStringContainsString("'active' => ['sometimes', 'boolean']", $store);
        self::assertStringContainsString("'birthday' => ['nullable', 'date']", $store);
        self::assertStringContainsString("'roles' => ['sometimes', 'array']", $store);
        self::assertStringContainsString('return true;', $store);
        self::assertStringContainsString("Rule::unique('users', 'email')->ignore(\$this->route('user'))", $update);
        self::assertStringContainsString("'email' => ['sometimes', 'string', 'max:255'", $update);
    }

    public function test_update_does_not_guess_a_route_parameter(): void
    {
        $metadata = new EntityMetadata(
            'User', 'users',
            uniqueConstraints: [new UniqueConstraintMetadata(['email'])],
            updateRules: ['email' => 'sometimes|string'],
        );

        $code = (new UpdateRequestGenerator())->generate($metadata, new GenerationContext('User'));

        self::assertStringNotContainsString('Rule::unique', $code);
        self::assertStringNotContainsString("route('", $code);
    }
}
