<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\Column;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\DataTableParams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
}

class TestArticle extends Model
{
    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = true;
}
