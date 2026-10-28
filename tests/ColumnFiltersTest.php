<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\Tests\Fixtures\Author;
use Givanov95\DataTable\Tests\Fixtures\Post;
use Givanov95\DataTable\Tests\Fixtures\Status;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Filters by column (`filter[status][]=…`, `filter[published_at][from]=…`),
 * run on every driver in CI.
 */
final class ColumnFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id');
            $table->string('status')->default('pending');
            $table->decimal('price', 8, 2)->default(0);
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('translatable_id');
            $table->string('translatable_type');
            $table->string('locale', 8);
            $table->string('key');
            $table->text('text');
        });

        $alice = Author::create(['name' => 'Alice']);
        $bob = Author::create(['name' => 'Bob']);

        // 23:30 UTC on the 27th is the 28th in Sofia (UTC+2).
        $p1 = Post::create(['author_id' => $alice->id, 'status' => 'pending', 'price' => 9.99, 'published_at' => '2026-10-27 23:30:00']);
        $p2 = Post::create(['author_id' => $alice->id, 'status' => 'approved', 'price' => 19.99, 'published_at' => '2026-10-28 12:00:00']);
        $p3 = Post::create(['author_id' => $bob->id, 'status' => 'rejected', 'price' => 29.99, 'published_at' => '2026-10-30 08:00:00']);

        $p1->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Hello world']);
        $p2->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Goodbye world']);
        $p3->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Another post']);
    }

    /** @param array<string, mixed> $query */
    private function table(array $query): DataTable
    {
        return (new DataTable(Post::query(), Request::create('/', 'GET', $query)))
            ->setColumn('id', '#', orderable: true)
            ->setColumn('status', 'Status', filterable: true)
            ->setEnumColumn('status', Status::class)
            ->setColumn('author_id', 'Author', filterable: true)
            ->setFilterOptions('author_id', fn () => Author::query()->orderBy('name')->pluck('name', 'id')->all())
            ->setColumn('published_at', 'Published', filterable: true)
            ->setDateColumn('published_at', 'd.m.Y H:i')
            ->setColumn('price', 'Price', searchable: true, filterable: true)
            ->setPriceColumn('price')
            ->setRelationColumn(new RelationColumn('author.name', 'Author name', searchable: true, filterable: true))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', filterable: true));
    }

    /** @return list<int> */
    private function ids(DataTable $table): array
    {
        return $table->getData()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function testAnEnumSelectTakesOneOrSeveralValues(): void
    {
        $this->assertSame([2], $this->ids($this->table(['filter' => ['status' => 'approved']])->process()));
        $this->assertSame([1, 3], $this->ids($this->table(['filter' => ['status' => ['pending', 'rejected']]])->process()));
    }

    public function testASelectComparesOnlyOfferedValues(): void
    {
        $table = $this->table(['filter' => ['status' => ['approved', "x' OR 1=1 --", 'Approved']]])->process();

        $this->assertSame([2], $this->ids($table));
        $this->assertSame(['approved'], $table->toArray()['state']['filters']['status']);

        // Nothing offered (a stale link): no filter, not an empty table with nothing saying why.
        $table = $this->table(['filter' => ['status' => 'nope']])->process();
        $this->assertSame([1, 2, 3], $this->ids($table));
        $this->assertSame([], $table->toArray()['state']['filters']);
    }

    public function testASelectWithTheAppsOptions(): void
    {
        $this->assertSame([3], $this->ids($this->table(['filter' => ['author_id' => '2']])->process()));
        $this->assertSame([1, 2, 3], $this->ids($this->table(['filter' => ['author_id' => '99']])->process()));

        // A plain list of values is its own labels.
        $table = $this->table([])->setFilterOptions('status', ['pending', 'approved'])->process();
        $this->assertSame([['value' => 'pending', 'label' => 'pending'], ['value' => 'approved', 'label' => 'approved']], array_column($table->toArray()['columns'], 'filter', 'key')['status']['options']);
    }

    public function testADateRangeIsTheUsersDays(): void
    {
        // The 28th in Sofia: the 23:30 UTC post of the 27th and the noon one.
        $table = $this->table(['filter' => ['published_at' => ['from' => '2026-10-28', 'to' => '2026-10-28'], 'timeZone' => 'Europe/Sofia']])->process();
        $this->assertSame([1, 2], $this->ids($table));

        // The server's (UTC) days without a zone.
        $this->assertSame([2], $this->ids($this->table(['filter' => ['published_at' => ['from' => '2026-10-28', 'to' => '2026-10-28']]])->process()));

        // Open ends.
        $this->assertSame([2, 3], $this->ids($this->table(['filter' => ['published_at' => ['from' => '2026-10-28']]])->process()));
        $this->assertSame([1, 2], $this->ids($this->table(['filter' => ['published_at' => ['to' => '2026-10-28']]])->process()));
    }

    public function testADateThatIsNotOneIsIgnored(): void
    {
        $table = $this->table(['filter' => ['published_at' => ['from' => '28.10.2026', 'to' => '2026-02-31']]])->process();

        $this->assertSame([1, 2, 3], $this->ids($table));
        $this->assertArrayNotHasKey('published_at', $table->toArray()['state']['filters']);
    }

    public function testATextFilterOnARelationAndATranslation(): void
    {
        $this->assertSame([3], $this->ids($this->table(['filter' => ['name' => 'bo']])->process()));
        $this->assertSame([1, 2], $this->ids($this->table(['filter' => ['title' => 'world']])->process()));
    }

    public function testADateBeforeYear1000IsIgnored(): void
    {
        // A date field passes through 0002-10-28 while the year is typed.
        $table = $this->table(['filter' => ['published_at' => ['from' => '0002-10-28']], 'timeZone' => 'Asia/Tokyo'])->process();

        $this->assertSame([1, 2, 3], $this->ids($table));
    }

    public function testATextFilterIsTrimmed(): void
    {
        $table = $this->table(['filter' => ['title' => ' world ']])->process();

        $this->assertSame([1, 2], $this->ids($table));
        $this->assertSame(['title' => 'world'], $table->toArray()['state']['filters']);
    }

    public function testATextFilterOnAPriceTakesTheDigits(): void
    {
        $this->assertSame([2], $this->ids($this->table(['filter' => ['price' => '$19']])->process()));
    }

    public function testFiltersAndWithEachOtherAndTheSearch(): void
    {
        $table = $this->table(['filter' => ['global' => 'Alice', 'status' => ['pending', 'approved', 'rejected'], 'title' => 'Hello']])->process();

        $this->assertSame([1], $this->ids($table));
    }

    public function testKeysThatAreNotFilterableColumnsAreIgnored(): void
    {
        // `id` is declared but not filterable; `secret` is not declared; arrays of arrays are dropped.
        $table = $this->table(['filter' => ['id' => '1', 'secret' => 'x', 'status' => [['approved']]]])->process();

        $this->assertSame([1, 2, 3], $this->ids($table));
        $this->assertSame([], $table->toArray()['state']['filters']);
    }

    public function testThePayloadDescribesEachFilter(): void
    {
        $payload = $this->table(['filter' => ['status' => 'approved', 'name' => 'Ali']])->process()->toArray();
        $columns = array_column($payload['columns'], null, 'key');

        $this->assertFalse($columns['id']['filterable']);
        $this->assertNull($columns['id']['filter']);
        $this->assertSame(['type' => 'select', 'options' => [
            ['value' => 'pending', 'label' => 'Pending'],
            ['value' => 'approved', 'label' => 'Approved'],
            ['value' => 'rejected', 'label' => 'Rejected'],
        ]], $columns['status']['filter']);
        $this->assertSame([['value' => 1, 'label' => 'Alice'], ['value' => 2, 'label' => 'Bob']], $columns['author_id']['filter']['options']);
        $this->assertSame(['type' => 'date'], $columns['published_at']['filter']);
        $this->assertSame(['type' => 'text'], $columns['title']['filter']);

        $this->assertSame(['status' => ['approved'], 'name' => 'Ali'], $payload['state']['filters']);
        $this->assertSame('filter', $payload['params']['column_filters']);
    }

    public function testAColumnCannotBeNamedLikeAnotherRequestKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DataTable(Post::query(), Request::create('/')))->setColumn('global', 'Global', filterable: true);
    }

    public function testTheFiltersParameterFollowsTheConfig(): void
    {
        Config::set('data-table.column_filters', 'where');

        $this->assertSame([2], $this->ids($this->table(['where' => ['status' => 'approved']])->process()));

        // With its own parameter, `global` is a column name like any other.
        (new DataTable(Post::query(), Request::create('/')))->setColumn('global', 'Global', filterable: true);
        $this->addToAssertionCount(1);
    }
}
