<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Inference;

use ByfallCode\ByfallCrud\Inference\RelationshipInferrer;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use PHPUnit\Framework\TestCase;

final class RelationshipInferrerTest extends TestCase
{
    public function test_it_infers_only_reliable_belongs_to_relations_from_real_foreign_keys(): void
    {
        $metadata = new EntityMetadata('Post', 'posts', columns: [
            new ColumnMetadata('category_id', foreignKey: new ForeignKeyMetadata('category_id', 'categories')),
            new ColumnMetadata('author_uuid', foreignKey: new ForeignKeyMetadata('author_uuid', 'users', 'uuid')),
        ]);
        $result = (new RelationshipInferrer())->infer($metadata);

        self::assertCount(2, $result['relationships']);
        self::assertSame('category', $result['relationships'][0]->name);
        self::assertSame('Category', $result['relationships'][0]->relatedModel);
        self::assertSame('author', $result['relationships'][1]->name);
        self::assertSame('uuid', $result['relationships'][1]->ownerKey);
        self::assertSame('belongsTo', $result['relationships'][1]->type);
        self::assertSame([], $result['diagnostics']);
    }

    public function test_ambiguous_names_are_flagged_instead_of_presented_as_certain(): void
    {
        $metadata = new EntityMetadata('Event', 'events', columns: [
            new ColumnMetadata('owner', foreignKey: new ForeignKeyMetadata('owner', 'users', 'uuid')),
        ]);
        $result = (new RelationshipInferrer())->infer($metadata);
        self::assertTrue($result['relationships'][0]->ambiguous);
        self::assertLessThan(0.9, $result['relationships'][0]->confidence);
        self::assertSame('AMBIGUOUS_RELATION_NAME', $result['diagnostics'][0]->code);
    }
}
