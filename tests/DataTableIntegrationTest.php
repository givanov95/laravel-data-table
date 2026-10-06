<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\Column;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\DataTableParams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class DataTableIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author');
            $table->integer('views')->default(0);
            $table->timestamps();
        });

        TestArticle::create(['title' => 'Apples are red',    'author' => 'Alice', 'views' => 10]);
        TestArticle::create(['title' => 'Bananas are yellow', 'author' => 'Bob',   'views' => 20]);
        TestArticle::create(['title' => 'Cherries are red',   'author' => 'Carol', 'views' => 30]);
    }

    public function testPositionalSetColumnApi(): void
    {
        $table = (new DataTable(TestArticle::query(), Request::create('/')))
            ->setColumn('id', '#', searchable: true, orderable: true)
            ->setColumn('title', 'Title', searchable: true, orderable: true)
            ->setColumn('author', 'Author', searchable: true)
            ->process();

        $this->assertCount(3, $table->getData());
        $this->assertSame(3, $table->getPaginator()->itemsLength);
    }

    public function testColumnObjectSetColumnApi(): void
    {
        $table = (new DataTable(TestArticle::query(), Request::create('/')))
            ->setColumn(new Column('id', '#', searchable: true, orderable: true))
            ->setColumn(new Column('title', 'Title', searchable: true))
            ->process();

        $this->assertCount(3, $table->getData());
    }

    public function testToArrayReturnsCleanContract(): void
    {
        $request = Request::create('/', 'GET', [
            'ordering' => ['key' => 'title', 'direction' => 'asc'],
            'filter'   => ['global' => 'red'],
        ]);

        $result = (new DataTable(TestArticle::query(), $request))
            ->setColumn('id', '#', searchable: true, orderable: true)
            ->setColumn('title', 'Title', searchable: true, orderable: true)
            ->process()
            ->toArray();

        // Standard, framework-friendly top-level shape.
        $this->assertSame(['data', 'meta', 'columns', 'state'], array_keys($result));

        // meta uses Laravel-paginator naming (not the internal camelCase shape).
        $this->assertSame(1, $result['meta']['current_page']);
        $this->assertSame(15, $result['meta']['per_page']);
        $this->assertSame(2, $result['meta']['total']); // "Apples are red" + "Cherries are red"
        $this->assertSame(1, $result['meta']['last_page']);
        $this->assertSame(1, $result['meta']['from']);
        $this->assertSame(2, $result['meta']['to']);

        // columns is an ordered array with clean keys.
        $this->assertSame([
            ['key' => 'id', 'label' => '#', 'sortable' => true, 'searchable' => true],
            ['key' => 'title', 'label' => 'Title', 'sortable' => true, 'searchable' => true],
        ], $result['columns']);

        // state echoes the applied request.
        $this->assertSame('red', $result['state']['search']);
        $this->assertSame(['column' => 'title', 'direction' => 'asc'], $result['state']['sort']);
        $this->assertFalse($result['state']['trashed']);

        $this->assertCount(2, $result['data']);
    }

    public function testGlobalFilterFindsRowsAcrossSearchableColumns(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => 'red']]);

        $table = (new DataTable(TestArticle::query(), $request))
            ->setColumn('id', '#', searchable: true, orderable: true)
            ->setColumn('title', 'Title', searchable: true)
            ->setColumn('author', 'Author', searchable: true)
            ->process();

        $this->assertCount(2, $table->getData());
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function wildcardSearchProvider(): array
    {
        return [
            'percent is literal'         => ['%', ['Discount 50% off']],
            'underscore is literal'      => ['_', ['snake_case title']],
            'percent between digits'     => ['0% o', ['Discount 50% off']],
            'underscore between letters' => ['e_c', ['snake_case title']],
            'backslash is literal'       => ['\\', ['C:\\temp\\file']],
            'wildcards do not combine'   => ['%_', []],
            'plain text still matches'   => ['red', ['Apples are red', 'Cherries are red']],
        ];
    }

    /**
     * @param  list<string>  $expectedTitles
     */
    #[DataProvider('wildcardSearchProvider')]
    public function testGlobalFilterTreatsLikeWildcardsAsLiterals(string $search, array $expectedTitles): void
    {
        TestArticle::create(['title' => 'Discount 50% off', 'author' => 'Dave', 'views' => 40]);
        TestArticle::create(['title' => 'snake_case title', 'author' => 'Erin', 'views' => 50]);
        TestArticle::create(['title' => 'snakeXcase title', 'author' => 'Frank', 'views' => 60]);
        TestArticle::create(['title' => 'C:\\temp\\file', 'author' => 'Grace', 'views' => 70]);

        $request = Request::create('/', 'GET', ['filter' => ['global' => $search]]);

        $table = (new DataTable(TestArticle::query(), $request))
            ->setColumn('title', 'Title', searchable: true)
            ->setColumn('author', 'Author', searchable: true)
            ->process();

        $titles = $table->getData()->pluck('title')->sort()->values()->all();
        sort($expectedTitles);

        $this->assertSame($expectedTitles, $titles);
    }

    public function testGlobalFilterStillSearchesNumericColumns(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => '20']]);

        $table = (new DataTable(TestArticle::query(), $request))
            ->setColumn('views', 'Views', searchable: true)
            ->process();

        $this->assertSame([20], $table->getData()->pluck('views')->all());
    }

    public function testOrderingByRequest(): void
    {
        $request = Request::create('/', 'GET', [
            'ordering' => ['key' => 'views', 'direction' => 'asc'],
        ]);

        $table = (new DataTable(TestArticle::query(), $request))
            ->setColumn('title', 'Title', orderable: true)
            ->setColumn('views', 'Views', orderable: true)
            ->process();

        $rows = $table->getData()->pluck('views')->all();
        $this->assertSame([10, 20, 30], $rows);
    }

    public function testQualifiesBareColumnsButLeavesAlreadyQualifiedSelects(): void
    {
        // A pre-existing qualified select must not get double-prefixed.
        $query = TestArticle::query()->select('articles.*');

        $table = (new DataTable($query, Request::create('/')))
            ->setColumn('title', 'Title', orderable: true)
            ->process();

        $this->assertCount(3, $table->getData());

        $bareQuery = TestArticle::query()->select('id', 'title');

        $table2 = (new DataTable($bareQuery, Request::create('/')))
            ->setColumn('title', 'Title')
            ->process();

        $this->assertCount(3, $table2->getData());
    }

    public function testParamsFromRequestRespectConfig(): void
    {
        $request = Request::create('/', 'GET', ['perPage' => 2]);
        $params = DataTableParams::fromRequest($request);

        $this->assertSame(2, $params->perPage);

        $table = (new DataTable(TestArticle::query(), $request))
            ->setColumn('id', '#')
            ->process();

        $this->assertSame(2, $table->getPaginator()->perPage);
        $this->assertSame(3, $table->getPaginator()->itemsLength);
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function requestedPerPageProvider(): array
    {
        return [
            'far above the maximum'  => [100000, 100],
            'exactly the maximum'    => [100, 100],
            'just above the maximum' => [101, 100],
            'numeric string'         => ['50', 50],
            'within bounds'          => [50, 50],
            'minimum'                => [1, 1],
            'zero'                   => [0, 15],
            'negative'               => [-5, 15],
            'minus one (was "all")'  => [-1, 15],
            'non-numeric'            => ['abc', 15],
            'trailing garbage'       => ['12abc', 15],
            'empty string'           => ['', 15],
            'array'                  => [['x'], 15],
            'nested array'           => [['a' => 1], 15],
        ];
    }

    #[DataProvider('requestedPerPageProvider')]
    public function testParamsFromRequestBoundPerPage(mixed $requested, int $expected): void
    {
        $request = Request::create('/', 'GET', ['perPage' => $requested]);

        $this->assertSame($expected, DataTableParams::fromRequest($request)->perPage);
    }

    public function testParamsFallBackToDefaultWhenPerPageIsMissing(): void
    {
        $params = DataTableParams::fromRequest(Request::create('/'));

        $this->assertSame(15, $params->perPage);
    }

    public function testMaxPerPageIsConfigurable(): void
    {
        Config::set('data-table.max_per_page', 500);

        $this->assertSame(300, DataTableParams::fromRequest(
            Request::create('/', 'GET', ['perPage' => 300]),
        )->perPage);
        $this->assertSame(500, DataTableParams::fromRequest(
            Request::create('/', 'GET', ['perPage' => 100000]),
        )->perPage);
    }

    public function testDefaultPerPageIsCappedByTheMaximum(): void
    {
        Config::set('data-table.default_per_page', 200);
        Config::set('data-table.max_per_page', 50);

        $this->assertSame(50, DataTableParams::fromRequest(Request::create('/'))->perPage);
        $this->assertSame(50, DataTableParams::fromRequest(
            Request::create('/', 'GET', ['perPage' => 0]),
        )->perPage);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidMaxPerPageProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-3],
            'null'     => [null],
        ];
    }

    #[DataProvider('invalidMaxPerPageProvider')]
    public function testInvalidMaxPerPageFallsBackInsteadOfDisablingTheBound(mixed $max): void
    {
        Config::set('data-table.max_per_page', $max);

        $this->assertSame(50, DataTableParams::fromRequest(
            Request::create('/', 'GET', ['perPage' => 100000]),
        )->perPage);
        $this->assertSame(15, DataTableParams::fromRequest(Request::create('/'))->perPage);
    }

    public function testInvalidDefaultPerPageIsKeptAtOne(): void
    {
        Config::set('data-table.default_per_page', 0);

        $this->assertSame(1, DataTableParams::fromRequest(Request::create('/'))->perPage);
    }

    public function testEmptyPerPageNoLongerReachesThePaginatorAsZero(): void
    {
        // The "default" option of the frontend's per-page select sends an empty
        // value; passed through as 0 it used to make paginate() divide by zero.
        $table = (new DataTable(TestArticle::query(), Request::create('/', 'GET', ['perPage' => ''])))
            ->setColumn('id', '#')
            ->process();

        $this->assertSame(15, $table->getPaginator()->perPage);
        $this->assertCount(3, $table->getData());
    }

    public function testProcessCapsAnOversizedPerPageRequest(): void
    {
        Config::set('data-table.max_per_page', 2);

        $request = Request::create('/', 'GET', ['perPage' => 100000]);

        $table = (new DataTable(TestArticle::query(), $request))
            ->setColumn('id', '#')
            ->process();

        $this->assertSame(2, $table->getPaginator()->perPage);
        $this->assertCount(2, $table->getData());
        $this->assertSame(3, $table->getPaginator()->itemsLength);
    }

    public function testExplicitParamsAreNotCapped(): void
    {
        $table = (new DataTable(TestArticle::query(), Request::create('/')))
            ->setColumn('id', '#')
            ->process(new DataTableParams(perPage: 5000));

        $this->assertSame(5000, $table->getPaginator()->perPage);
    }
}

class TestArticle extends Model
{
    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = true;
}
