<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\RawOrdering;
use Givanov95\DataTable\Tests\Fixtures\Author;
use Givanov95\DataTable\Tests\Fixtures\Post;
use Givanov95\DataTable\Tests\Fixtures\PostPolicy;
use Givanov95\DataTable\Tests\Fixtures\Status;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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

    public function testTrashedFilterShowsOnlyDeletedRecords(): void
    {
        Post::find(1)->delete();

        $trashedRequest = Request::create('/', 'GET', ['filter' => ['trashed' => 'true']]);

        $table = (new DataTable(Post::query(), $trashedRequest))
            ->setColumn('id', '#')
            ->process();

        $this->assertCount(1, $table->getData());
        $this->assertSame(1, $table->getData()->first()->id);
    }

    public function testSoftRestoreReinstatesDeletedRecord(): void
    {
        $post = Post::find(2);
        $post->delete();
        $this->assertNotNull($post->fresh()->deleted_at);

        $request = Request::create('/', 'GET', ['restore_id' => 2]);

        // Register a permissive policy so SoftRestorer's authorize() call passes.
        \Illuminate\Support\Facades\Gate::policy(Post::class, PostPolicy::class);

        (new DataTable(Post::query(), $request))
            ->setColumn('id', '#')
            ->process();

        $this->assertNull(Post::find(2)->deleted_at);
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
