<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\GenerationContext;
use ByfallCode\ByfallCrud\Generation\QueryApplierGenerator;
use ByfallCode\ByfallCrud\Generation\QueryParserGenerator;
use ByfallCode\ByfallCrud\Generation\QuerySpecificationGenerator;
use ByfallCode\ByfallCrud\Generation\RepositoryGenerator;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class QueryApiGeneratorsTest extends TestCase
{
    private static EntityMetadata $metadata;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$metadata = self::metadata();
        $context = new GenerationContext('Product');
        foreach ([new QuerySpecificationGenerator(), new QueryParserGenerator(), new QueryApplierGenerator()] as $generator) {
            $code = $generator->generate(self::$metadata, $context);
            eval(substr($code, strlen("<?php\n")));
        }
    }

    public function test_parser_accepts_only_metadata_allowlists_and_neutralizes_malicious_values(): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(Request::create('/products', 'GET', [
            'search' => "%' OR 1=1 --",
            'category_id' => '4',
            'active' => 'false',
            'unknown_column' => 'secret',
            'password' => 'secret',
            'api_token' => 'value',
            'sort' => 'name desc; DROP TABLE users',
            'direction' => 'desc;delete',
            'include' => 'category,passwords,category.parent,category',
            'per_page' => '1000000',
        ]));

        self::assertSame("%' OR 1=1 --", $specification->search);
        self::assertSame(['category_id' => '4', 'active' => false], $specification->filters);
        self::assertNull($specification->sort);
        self::assertSame('asc', $specification->direction);
        self::assertSame(['category'], $specification->includes);
        self::assertSame(100, $specification->perPage);
    }

    #[DataProvider('booleanValues')]
    public function test_parser_normalizes_supported_boolean_values(string $input, bool $expected): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(
            Request::create('/products', 'GET', ['active' => $input]),
        );

        self::assertSame($expected, $specification->filters['active']);
    }

    public static function booleanValues(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
    }

    #[DataProvider('paginationValues')]
    public function test_parser_bounds_pagination_values(string $input, int $expected): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(
            Request::create('/products', 'GET', ['per_page' => $input]),
        );

        self::assertSame($expected, $specification->perPage);
    }

    public static function paginationValues(): iterable
    {
        yield 'minimum' => ['1', 1];
        yield 'default value' => ['15', 15];
        yield 'maximum' => ['100', 100];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-1', 1];
        yield 'over maximum' => ['101', 100];
        yield 'unreasonable' => ['1000000', 100];
        yield 'invalid' => ['abc', 15];
    }

    #[DataProvider('directionValues')]
    public function test_parser_normalizes_sort_directions(string $input, string $expected): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(
            Request::create('/products', 'GET', ['sort' => 'price', 'direction' => $input]),
        );

        self::assertSame($expected, $specification->direction);
    }

    public static function directionValues(): iterable
    {
        yield 'asc' => ['asc', 'asc'];
        yield 'uppercase asc' => ['ASC', 'asc'];
        yield 'desc' => ['desc', 'desc'];
        yield 'uppercase desc' => ['DESC', 'desc'];
        yield 'invalid' => ['sideways', 'asc'];
    }

    public function test_invalid_values_are_ignored_or_bounded_safely(): void
    {
        $parser = new \App\Queries\ProductQueryParser();
        $invalid = $parser->parse(Request::create('/products', 'GET', [
            'active' => 'perhaps',
            'category_id' => ['4', '5'],
            'search' => ['unsafe'],
            'sort' => ['price'],
            'direction' => ['desc'],
            'include' => ['category'],
            'per_page' => 'many',
        ]));
        $minimum = $parser->parse(Request::create('/products', 'GET', ['per_page' => '0']));

        self::assertSame([], $invalid->filters);
        self::assertNull($invalid->search);
        self::assertNull($invalid->sort);
        self::assertSame([], $invalid->includes);
        self::assertSame(15, $invalid->perPage);
        self::assertSame(1, $minimum->perPage);
    }

    public function test_parser_preserves_an_allowlisted_string_filter(): void
    {
        $metadata = new EntityMetadata(
            name: 'Article',
            table: 'articles',
            columns: [new ColumnMetadata('status')],
            filterable: ['status'],
        );
        $context = new GenerationContext('Article');
        foreach ([new QuerySpecificationGenerator(), new QueryParserGenerator()] as $generator) {
            $code = $generator->generate($metadata, $context);
            eval(substr($code, strlen("<?php\n")));
        }

        $specification = (new \App\Queries\ArticleQueryParser())->parse(
            Request::create('/articles', 'GET', ['status' => 'published']),
        );

        self::assertSame(['status' => 'published'], $specification->filters);
    }

    public function test_applier_uses_bound_values_and_validated_columns_only(): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(Request::create('/products', 'GET', [
            'search' => "%' OR 1=1 --",
            'category_id' => '4',
            'active' => '0',
            'sort' => 'price',
            'direction' => 'DESC',
            'include' => 'category',
        ]));
        $query = (new \App\Queries\ProductQueryApplier())->apply(QueryApiProduct::query(), $specification);

        self::assertStringNotContainsString("OR 1=1", $query->toSql());
        self::assertSame(["%%' OR 1=1 --%", "%%' OR 1=1 --%", '4', false], $query->getBindings());
        self::assertStringContainsString('order by "price" desc', $query->toSql());
        self::assertSame(['category'], array_keys($query->getEagerLoads()));
    }

    public function test_default_sort_is_the_allowlisted_primary_key_and_includes_are_opt_in(): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(Request::create('/products'));
        $query = (new \App\Queries\ProductQueryApplier())->apply(QueryApiProduct::query(), $specification);

        self::assertStringContainsString('order by "id" desc', $query->toSql());
        self::assertSame([], $query->getEagerLoads());
    }

    public function test_applier_does_not_invent_a_default_sort_without_a_safe_primary_key(): void
    {
        $metadata = new EntityMetadata('UnsortedProduct', 'products', sortable: ['name']);
        $context = new GenerationContext('UnsortedProduct');
        foreach ([new QuerySpecificationGenerator(), new QueryApplierGenerator()] as $generator) {
            $code = $generator->generate($metadata, $context);
            eval(substr($code, strlen("<?php\n")));
        }
        $specification = new \App\Queries\UnsortedProductQuerySpecification(null, [], null, 'asc', [], 15);
        $query = (new \App\Queries\UnsortedProductQueryApplier())->apply(QueryApiProduct::query(), $specification);

        self::assertStringNotContainsString('order by', strtolower($query->toSql()));
    }

    public function test_specification_preserves_only_valid_parameters_in_pagination_links(): void
    {
        $specification = (new \App\Queries\ProductQueryParser())->parse(Request::create('/products', 'GET', [
            'search' => 'phone',
            'active' => 'true',
            'sort' => 'price',
            'direction' => 'desc',
            'include' => 'category,passwords',
            'per_page' => '20',
            'unknown' => 'discarded',
        ]));
        $paginator = new LengthAwarePaginator(range(1, 20), 40, 20, 1, ['path' => 'https://example.test/products']);
        $paginator->appends($specification->queryParameters());
        $next = (string) $paginator->nextPageUrl();

        self::assertStringContainsString('search=phone', $next);
        self::assertStringContainsString('active=1', $next);
        self::assertStringContainsString('sort=price', $next);
        self::assertStringContainsString('direction=desc', $next);
        self::assertStringContainsString('include=category', $next);
        self::assertStringContainsString('per_page=20', $next);
        self::assertStringNotContainsString('unknown', $next);
        self::assertStringNotContainsString('password', $next);
    }

    public function test_generators_emit_runtime_independent_valid_php_with_strict_boundaries(): void
    {
        $context = new GenerationContext('Product');
        $artifacts = [
            (new QuerySpecificationGenerator())->generate(self::$metadata, $context),
            (new QueryParserGenerator())->generate(self::$metadata, $context),
            (new QueryApplierGenerator())->generate(self::$metadata, $context),
            (new RepositoryGenerator())->generate(self::$metadata, $context),
        ];

        self::assertStringContainsString("private const FILTERABLE = [\n        'category_id',\n        'active',\n    ];", $artifacts[1]);
        self::assertStringContainsString("private const SORTABLE = [\n        'id',\n        'name',\n        'price',", $artifacts[1]);
        self::assertStringContainsString("private const SEARCHABLE = [\n        'name',\n        'description',", $artifacts[2]);
        self::assertStringContainsString('paginate(ProductQuerySpecification $specification)', $artifacts[3]);
        self::assertStringNotContainsString('Illuminate\\Http\\Request', $artifacts[3]);
        self::assertStringNotContainsString('ByfallCode\\ByfallCrud', implode("\n", $artifacts));
        foreach (['information_schema', 'pg_catalog', 'Schema::', 'MigrationSchemaSource', 'SensitiveFieldPolicy', 'whereRaw', 'DB::raw'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, implode("\n", $artifacts));
        }
        foreach ($artifacts as $code) {
            $this->assertPhpValid($code);
        }
    }

    private static function metadata(): EntityMetadata
    {
        return new EntityMetadata(
            name: 'Product',
            table: 'products',
            columns: [
                new ColumnMetadata('id', normalizedType: 'integer', primary: true),
                new ColumnMetadata('category_id', normalizedType: 'integer'),
                new ColumnMetadata('name'),
                new ColumnMetadata('description', normalizedType: 'text'),
                new ColumnMetadata('price', normalizedType: 'decimal'),
                new ColumnMetadata('active', normalizedType: 'boolean'),
            ],
            searchable: ['name', 'description'],
            filterable: ['category_id', 'active', 'search'],
            sortable: ['id', 'name', 'price', 'created_at', 'updated_at'],
            allowedIncludes: ['category'],
        );
    }

    private function assertPhpValid(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'byfall-query-');
        file_put_contents($file, $code);
        try {
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        } finally {
            @unlink($file);
        }
    }
}

final class QueryApiProduct extends Model
{
    protected $table = 'products';
}
