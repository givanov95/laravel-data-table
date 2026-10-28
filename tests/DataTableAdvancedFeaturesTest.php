<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\RawOrdering;
use Givanov95\DataTable\Tests\Fixtures\Author;
use Givanov95\DataTable\Tests\Fixtures\DenyingPostPolicy;
use Givanov95\DataTable\Tests\Fixtures\Post;
use Givanov95\DataTable\Tests\Fixtures\PostPolicy;
use Givanov95\DataTable\Tests\Fixtures\Status;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class DataTableAdvancedFeaturesTest extends TestCase
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

        $p1 = Post::create(['author_id' => $alice->id, 'status' => Status::Pending->value,  'price' => 9.99,  'published_at' => '2026-01-15 10:00:00']);
        $p2 = Post::create(['author_id' => $alice->id, 'status' => Status::Approved->value, 'price' => 19.99, 'published_at' => '2026-02-20 12:00:00']);
        $p3 = Post::create(['author_id' => $bob->id,   'status' => Status::Rejected->value, 'price' => 29.99, 'published_at' => '2026-03-25 18:00:00']);

        $p1->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Hello world']);
        $p1->translations()->create(['locale' => 'bg', 'key' => 'title', 'text' => 'Здравей свят']);
        $p2->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Goodbye world']);
        $p3->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Another post']);
    }

    public function testRelationColumnGlobalFilter(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => 'Bob']]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#', searchable: true, orderable: true)
            ->setRelationColumn(new RelationColumn('author.name', 'Author', searchable: true))
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertSame(3, $table->getData()->first()->id);
    }

    public function testTranslatableColumnPopulatesValueAndIsSearchable(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => 'Goodbye']]);

        $table = (new DataTable(Post::query()->with('translations'), $request))
            ->setColumn('id', '#', searchable: true)
            ->setTranslatableColumn(new TranslatableColumn(
                locale: 'en',
                translationKey: 'title',
                label: 'Title',
                searchable: true,
            ))
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertSame('Goodbye world', $table->getData()->first()->title);
    }

    public function testRelationColumnGlobalFilterTreatsWildcardsAsLiterals(): void
    {
        $promo = Author::create(['name' => 'Half%_Price']);
        $promoPost = Post::create(['author_id' => $promo->id]);

        $expected = [
            '%'    => [$promoPost->id],
            '_'    => [$promoPost->id],
            'f%_P' => [$promoPost->id],
            // As a wildcard pattern this would find Alice (an "A", then anything, then an "e").
            'A%e' => [],
        ];

        foreach ($expected as $search => $ids) {
            $request = Request::create('/', 'GET', ['filter' => ['global' => (string) $search]]);

            $table = (new DataTable(Post::query(), $request))
                ->setColumn('id', '#')
                ->setRelationColumn(new RelationColumn('author.name', 'Author', searchable: true))
                ->process();

            $this->assertSame($ids, $table->getData()->pluck('id')->all(), "Searching for \"{$search}\"");
        }
    }

    public function testTranslatableColumnGlobalFilterTreatsWildcardsAsLiterals(): void
    {
        $sale = Post::create(['author_id' => 1]);
        $sale->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Sale 50% off_final']);

        foreach (['%', '_', '0% o', 'f_f'] as $search) {
            $request = Request::create('/', 'GET', ['filter' => ['global' => $search]]);

            $table = (new DataTable(Post::query()->with('translations'), $request))
                ->setColumn('id', '#')
                ->setTranslatableColumn(new TranslatableColumn(
                    locale: 'en',
                    translationKey: 'title',
                    label: 'Title',
                    searchable: true,
                ))
                ->process();

            $this->assertSame(
                [$sale->id],
                $table->getData()->pluck('id')->all(),
                "Searching for \"{$search}\"",
            );
        }
    }

    public function testPriceColumnFilterIgnoresWildcardsInTheInput(): void
    {
        // 100.90 is stored as 100.9: "1%9" would match it as a wildcard pattern
        // (a 1, then anything, then a 9); read literally the digits are "19".
        Post::create(['author_id' => 1, 'price' => 100.90]);

        $request = Request::create('/', 'GET', ['filter' => ['global' => '1%9']]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('price', 'Price', searchable: true)
            ->setPriceColumn('price')
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertEqualsWithDelta(19.99, (float) $table->getData()->first()->price, 0.01);
    }

    public function testEnumColumnFilterMatchesByCaseName(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => 'approved']]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#', searchable: true)
            ->setColumn('status', 'Status', searchable: true)
            ->setEnumColumn('status', Status::class)
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertSame(Status::Approved->value, $table->getData()->first()->status);
    }

    public function testPriceColumnStripsNonNumericFromFilter(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => '$19']]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('price', 'Price', searchable: true)
            ->setPriceColumn('price')
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertEqualsWithDelta(19.99, (float) $table->getData()->first()->price, 0.01);
    }

    public function testPriceSearchIsQualifiedWhenARelationOrderingJoinsAColumnOfTheSameName(): void
    {
        Schema::table('authors', fn (Blueprint $table) => $table->decimal('price', 8, 2)->default(0));

        $request = Request::create('/', 'GET', [
            'filter'   => ['global' => '$19'],
            'ordering' => ['key' => 'author.name', 'direction' => 'asc'],
        ]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('price', 'Price', searchable: true)
            ->setRelationColumn(new RelationColumn('author.name', 'Author', orderable: true))
            ->setPriceColumn('price')
            ->process();

        $this->assertSame([2], $table->getData()->pluck('id')->all());
    }

    /** @return array<string, array{string}> */
    public static function valuesThatAreNotAnId(): array
    {
        return [
            'text'      => ['abc'],
            'a decimal' => ['1.5'],
            'too large' => ['99999999999999999999'],
            // Past 32 bits: an `integer` column in PostgreSQL rejects it (22003).
            'past 32 bits' => ['3000000000'],
        ];
    }

    #[DataProvider('valuesThatAreNotAnId')]
    public function testAnExactMatchOnAnIntegerColumnSkipsAValueThatIsNotOne(string $term): void
    {
        // On PostgreSQL, `id = 'abc'` is an error (22P02), `id = '9999…'` too (22003).
        $table = (new DataTable(Post::query(), Request::create('/', 'GET', ['filter' => ['global' => $term]])))
            ->setColumn('id', '#', searchable: true, exactMatch: true)
            ->setColumn('status', 'Status', searchable: true)
            ->process();

        $this->assertSame([], $table->getData()->all());

        // The only searchable column skipped: still no rows, not every row.
        $table = (new DataTable(Post::query(), Request::create('/', 'GET', ['filter' => ['global' => $term]])))
            ->setColumn('id', '#', searchable: true, exactMatch: true)
            ->process();

        $this->assertSame([], $table->getData()->all());

        // The other searchable columns still count.
        $table = (new DataTable(Post::query(), Request::create('/', 'GET', ['filter' => ['global' => '2']])))
            ->setColumn('id', '#', searchable: true, exactMatch: true)
            ->process();

        $this->assertSame([2], $table->getData()->pluck('id')->all());
    }

    public function testAnExactFilterOnAnIntegerColumnWithAValueThatIsNotOneMatchesNothing(): void
    {
        $table = (new DataTable(Post::query(), Request::create('/')))->setColumn('id', '#', exactMatch: true);

        $builder = (new \Givanov95\DataTable\ColumnFilter($table))->apply(Post::query(), 'id', 'abc')->getBuilder();

        $this->assertSame(0, $builder->count());
    }

    public function testTrashedFilterShowsOnlyDeletedRecords(): void
    {
        Post::find(1)->delete();

        $trashedRequest = Request::create('/', 'GET', ['filter' => ['trashed' => 'true']]);

        $table = (new DataTable(Post::query(), $trashedRequest))
            ->setColumn('id', '#')
            ->allowTrashed()
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertSame(1, $table->getData()->first()->id);
        $this->assertTrue($table->trashed);
        $this->assertTrue($table->toArray()['state']['trashed']);
    }

    public function testTheArchiveIsIgnoredUnlessTheTableAllowsIt(): void
    {
        Post::find(1)->delete();

        $table = (new DataTable(Post::query(), Request::create('/', 'GET', ['filter' => ['trashed' => 'true']])))
            ->setColumn('id', '#')
            ->process();

        $this->assertEqualsCanonicalizing([2, 3], $table->getData()->pluck('id')->all());
        $this->assertFalse($table->trashed);
        $this->assertFalse($table->toArray()['state']['trashed']);
    }

    public function testTheArchiveIsIgnoredForAModelWithoutSoftDeletes(): void
    {
        $table = (new DataTable(Author::query(), Request::create('/', 'GET', ['filter' => ['trashed' => 'true']])))
            ->setColumn('id', '#')
            ->allowTrashed()
            ->process();

        $this->assertCount(2, $table->getData());
        $this->assertFalse($table->toArray()['state']['trashed']);
    }

    public function testTheArchiveFollowsTheAbility(): void
    {
        Post::find(1)->delete();
        $request = Request::create('/', 'GET', ['filter' => ['trashed' => 'true']]);
        $seen = [];
        Gate::define('viewArchive', function (?object $user, string $model) use (&$seen): bool {
            $seen[] = $model;

            return false;
        });

        $denied = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#')
            ->allowTrashed(ability: 'viewArchive')
            ->process();

        $this->assertEqualsCanonicalizing([2, 3], $denied->getData()->pluck('id')->all());
        $this->assertFalse($denied->toArray()['state']['trashed']);
        $this->assertSame([Post::class], $seen);

        Gate::define('viewArchive', fn (?object $user): bool => true);

        $allowed = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#')
            ->allowTrashed(ability: 'viewArchive')
            ->process();

        $this->assertSame([1], $allowed->getData()->pluck('id')->all());
    }

    public function testRestoreIdInTheQueryStringNoLongerRestores(): void
    {
        Post::find(2)->delete();
        Gate::policy(Post::class, PostPolicy::class);

        $request = Request::create('/', 'GET', ['restore_id' => 2, 'filter' => ['trashed' => 'true']]);

        (new DataTable(Post::query(), $request))
            ->setColumn('id', '#')
            ->allowTrashed()
            ->process();

        $this->assertNotNull(Post::withTrashed()->find(2)->deleted_at);
    }

    public function testRestoreReinstatesADeletedRecord(): void
    {
        Post::find(2)->delete();
        Gate::policy(Post::class, PostPolicy::class);

        $response = (new DataTable(Post::query(), Request::create('/', 'POST')))
            ->allowTrashed()
            ->restore(2);

        $this->assertNull(Post::find(2)->deleted_at);
        $this->assertTrue($response->isRedirect());
    }

    public function testRestoreChecksTheRestorePolicy(): void
    {
        Post::find(2)->delete();
        Gate::policy(Post::class, DenyingPostPolicy::class);

        try {
            (new DataTable(Post::query(), Request::create('/', 'POST')))->allowTrashed()->restore(2);
            $this->fail('The restore policy must be checked.');
        } catch (AuthorizationException) {
        }

        $this->assertNotNull(Post::withTrashed()->find(2)->deleted_at);
    }

    public function testRestoreOnlyFindsRecordsOfTheTablesQuery(): void
    {
        // Post 3 is Bob's; the table lists Alice's posts only.
        Post::find(3)->delete();
        Post::find(2)->delete();
        Gate::policy(Post::class, PostPolicy::class);

        $table = fn () => (new DataTable(Post::query()->where('author_id', 1), Request::create('/', 'POST')))->allowTrashed();

        $this->assertNotFound(fn () => $table()->restore(3));
        $this->assertNotNull(Post::withTrashed()->find(3)->deleted_at);

        // One of the table's own records is restored.
        $table()->restore(2);
        $this->assertNull(Post::find(2)->deleted_at);
    }

    public function testRestoreHonoursTheScope(): void
    {
        Post::find(3)->delete();
        Gate::policy(Post::class, PostPolicy::class);

        $this->assertNotFound(fn () => (new DataTable(Post::query(), Request::create('/', 'POST')))
            ->allowTrashed()
            ->restore(3, fn ($q) => $q->where('author_id', 1)));

        $this->assertNotNull(Post::withTrashed()->find(3)->deleted_at);
    }

    public function testRestoreOfAnIdThatIsNotANumberIsNotFound(): void
    {
        Gate::policy(Post::class, PostPolicy::class);

        $this->assertNotFound(fn () => (new DataTable(Post::query(), Request::create('/', 'POST')))->allowTrashed()->restore('abc'));
    }

    public function testRestoreFindsOnlyArchivedRecords(): void
    {
        Gate::policy(Post::class, PostPolicy::class);

        $this->assertNotFound(fn () => (new DataTable(Post::query(), Request::create('/', 'POST')))->allowTrashed()->restore(2));
    }

    public function testRestoreNeedsTheArchive(): void
    {
        Post::find(2)->delete();
        Gate::policy(Post::class, PostPolicy::class);

        // Not allowed for the table.
        $this->assertNotFound(fn () => (new DataTable(Post::query(), Request::create('/', 'POST')))->restore(2));

        // A model without SoftDeletes: a 404, not a BadMethodCallException.
        $this->assertNotFound(fn () => (new DataTable(Author::query(), Request::create('/', 'POST')))->allowTrashed()->restore(1));

        // The ability of allowTrashed() refuses: a 403.
        Gate::define('viewArchive', fn (?object $user): bool => false);

        try {
            (new DataTable(Post::query(), Request::create('/', 'POST')))->allowTrashed(ability: 'viewArchive')->restore(2);
            $this->fail('The ability must be checked.');
        } catch (AuthorizationException) {
        }

        $this->assertNotNull(Post::withTrashed()->find(2)->deleted_at);
    }

    private function assertNotFound(callable $restore): void
    {
        try {
            $restore();
            $this->fail('The record must not be found.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRawOrderingReportsWhatItSaysItAmountsTo(): void
    {
        $request = Request::create('/', 'GET', ['ordering' => ['key' => 'id', 'direction' => 'asc']]);

        $plain = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#', orderable: true)
            ->setRawOrdering(new RawOrdering('price DESC'))
            ->process();
        $this->assertNull($plain->toArray()['state']['sort']);

        $reported = (new DataTable(Post::query(), $request))
            ->setColumn('price', 'Price', orderable: true)
            ->setRawOrdering(new RawOrdering('price DESC, id DESC', new \Givanov95\DataTable\Ordering('price', 'DESC')))
            ->process();
        $this->assertSame(['column' => 'price', 'direction' => 'desc'], $reported->toArray()['state']['sort']);
    }

    public function testThePayloadNamesTheRequestKeysOfTheConfig(): void
    {
        config(['data-table.per_page' => 'per_page', 'data-table.global_filter' => 'filter.search']);

        $params = (new DataTable(Post::query(), Request::create('/')))->setColumn('id', '#')->process()->toArray()['params'];

        $this->assertSame([
            'per_page'      => 'per_page',
            'global_filter' => 'filter.search',
            'trashed'       => 'filter.trashed',
            'ordering'      => 'ordering',
            'time_zone'     => 'filter.timeZone',
        ], $params);
    }

    public function testRawOrderingWinsOverNormalOrdering(): void
    {
        $request = Request::create('/', 'GET', [
            'ordering' => ['key' => 'id', 'direction' => 'asc'],
        ]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#', orderable: true)
            ->setRawOrdering(new RawOrdering('price DESC'))
            ->process();

        $prices = $table->getData()->pluck('price')->map(fn ($p) => (float) $p)->all();

        $this->assertSame([29.99, 19.99, 9.99], $prices);
    }

    public function testAdvancedSearchCallbackMutatesQuery(): void
    {
        $table = (new DataTable(Post::query(), Request::create('/')))
            ->setColumn('id', '#')
            ->advancedSearch(fn ($q) => $q->where('price', '>', 15))
            ->process();

        $this->assertCount(2, $table->getData());
    }

    public function testCallbackBeforePaginateFiltersResults(): void
    {
        $request = Request::create('/');

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#')
            ->process(null, fn ($query) => $query->where('author_id', 2));

        $this->assertCount(1, $table->getData());
        $this->assertSame(3, $table->getData()->first()->id);
    }
}
