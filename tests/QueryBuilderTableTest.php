<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\QueryBuilderTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function wildcardSearchProvider(): array
    {
        return [
            'percent is literal'       => ['%', ['Discount 50% off']],
            'underscore is literal'    => ['_', ['snake_case title']],
            'percent between digits'   => ['0% o', ['Discount 50% off']],
            'backslash is literal'     => ['\\', ['C:\\temp\\file']],
            'wildcards do not combine' => ['%_', []],
            'plain text still matches' => ['red', ['Apples are red', 'Cherries are red']],
        ];
    }

    /**
     * @param  list<string>  $expectedTitles
     */
    #[DataProvider('wildcardSearchProvider')]
    public function testGlobalSearchTreatsLikeWildcardsAsLiterals(string $search, array $expectedTitles): void
    {
        QbtArticle::create(['title' => 'Discount 50% off', 'author' => 'Dave']);
        QbtArticle::create(['title' => 'snake_case title', 'author' => 'Erin']);
        QbtArticle::create(['title' => 'snakeXcase title', 'author' => 'Frank']);
        QbtArticle::create(['title' => 'C:\\temp\\file', 'author' => 'Grace']);

        $payload = $this->table(Request::create('/', 'GET', ['filter' => ['global' => $search]]))->toArray();

        $titles = array_column($payload['data'], 'title');
        sort($titles);
        sort($expectedTitles);

        $this->assertSame($expectedTitles, $titles);
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

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function requestedPerPageProvider(): array
    {
        return [
            'far above the maximum' => [100000, 100],
            'exactly the maximum'   => [100, 100],
            'within bounds'         => [2, 2],
            'zero'                  => [0, 15],
            'negative'              => [-5, 15],
            'minus one (was "all")' => [-1, 15],
            'non-numeric'           => ['abc', 15],
            'empty string'          => ['', 15],
            'array'                 => [['x'], 15],
        ];
    }

    #[DataProvider('requestedPerPageProvider')]
    public function testPerPageIsBoundedByTheSharedRule(mixed $requested, int $expected): void
    {
        $payload = $this->table(Request::create('/', 'GET', ['perPage' => $requested]))->toArray();

        $this->assertSame($expected, $payload['meta']['per_page']);
    }

    public function testMaxPerPageFollowsTheConfig(): void
    {
        Config::set('data-table.max_per_page', 2);

        $payload = $this->table(Request::create('/', 'GET', ['perPage' => 100000]))->toArray();

        $this->assertSame(2, $payload['meta']['per_page']);
        $this->assertCount(2, $payload['data']);
        $this->assertSame(3, $payload['meta']['total']);
    }

    public function testMaxPerPageCanBeOverriddenPerTable(): void
    {
        $payload = $this->table(Request::create('/', 'GET', ['perPage' => 100000]))
            ->maxPerPage(1)
            ->toArray();

        $this->assertSame(1, $payload['meta']['per_page']);
        $this->assertCount(1, $payload['data']);
    }

    public function testDefaultPerPageIsCappedByTheMaximum(): void
    {
        $payload = $this->table(Request::create('/'))
            ->defaultPerPage(200)
            ->toArray();

        $this->assertSame(100, $payload['meta']['per_page']);
    }

    public function testPerTableMaximumAlsoCapsTheFallbackDefault(): void
    {
        $payload = $this->table(Request::create('/', 'GET', ['perPage' => 0]))
            ->maxPerPage(2)
            ->toArray();

        $this->assertSame(2, $payload['meta']['per_page']);
        $this->assertCount(2, $payload['data']);
    }

    public function testInvalidMaxPerPageInConfigFallsBackToFifty(): void
    {
        Config::set('data-table.max_per_page', 0);

        $payload = $this->table(Request::create('/', 'GET', ['perPage' => 100000]))->toArray();

        $this->assertSame(50, $payload['meta']['per_page']);
    }

    public function testMaxPerPageRejectsNonPositiveValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->table(Request::create('/'))->maxPerPage(0);
    }
}

class QbtArticle extends Model
{
    use SoftDeletes;

    protected $table = 'qbt_articles';

    protected $guarded = [];
}
