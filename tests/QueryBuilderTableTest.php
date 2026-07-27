<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\QueryBuilderTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class QueryBuilderTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('qbt_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author');
            $table->string('secret')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        QbtArticle::create(['title' => 'Apples are red', 'author' => 'Alice', 'secret' => 'A1']);
        QbtArticle::create(['title' => 'Bananas are yellow', 'author' => 'Bob', 'secret' => 'B2']);
        QbtArticle::create(['title' => 'Cherries are red', 'author' => 'Carol', 'secret' => 'C3']);
    }

    private function table(Request $request): QueryBuilderTable
    {
        return QueryBuilderTable::for(QbtArticle::query(), $request)
            ->columns([
                ['key' => 'id', 'label' => '#', 'sortable' => true],
                ['key' => 'title', 'label' => 'Title', 'sortable' => true, 'searchable' => true],
                ['key' => 'author', 'label' => 'Author', 'searchable' => true],
            ])
            ->allowTrashed()
            ->defaultSort('id', 'desc')
            ->transform(fn (QbtArticle $article): array => [
                'id'     => $article->id,
                'title'  => $article->title,
                'author' => $article->author,
            ]);
    }

    public function testEmitsTheContractShape(): void
    {
        $payload = $this->table(Request::create('/'))->toArray();

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('columns', $payload);
        $this->assertArrayHasKey('state', $payload);
        $this->assertSame(3, $payload['meta']['total']);
        $this->assertCount(3, $payload['columns']);
    }

    public function testGlobalSearchOrsTheSearchableColumns(): void
    {
        $payload = $this->table(Request::create('/?filter[global]=Apples'))->toArray();

        $this->assertCount(1, $payload['data']);
        $this->assertSame('Apples are red', $payload['data'][0]['title']);
    }

    public function testOrderingSortsBySortableColumn(): void
    {
        $request = Request::create('/?ordering[key]=title&ordering[direction]=asc');
        $payload = $this->table($request)->toArray();

        $this->assertSame('Apples are red', $payload['data'][0]['title']);
        $this->assertSame(['column' => 'title', 'direction' => 'asc'], $payload['state']['sort']);
    }

    public function testComposableFilterParamsDoNotThrow(): void
    {
        // The composable sends filter[timeZone] with every search and
        // filter[trashed] on the archived toggle. An adapter that allows only
        // `global` would trip Spatie's InvalidFilterQuery here.
        $request = Request::create('/?filter[global]=a&filter[timeZone]=UTC&filter[trashed]=true');
        $payload = $this->table($request)->toArray();

        $this->assertArrayHasKey('data', $payload);
        $this->assertTrue($payload['state']['trashed']);
    }

    public function testTransformWhitelistsColumns(): void
    {
        $payload = $this->table(Request::create('/'))->toArray();

        $this->assertArrayHasKey('title', $payload['data'][0]);
        $this->assertArrayNotHasKey('secret', $payload['data'][0]);
    }
}

class QbtArticle extends Model
{
    use SoftDeletes;

    protected $table = 'qbt_articles';

    protected $guarded = [];
}
