<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\Ordering;
use Givanov95\DataTable\Tests\Fixtures\Author;
use Givanov95\DataTable\Tests\Fixtures\Post;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class DataTableOrderingTest extends TestCase
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

        // Default ordering (id DESC) yields [3, 2, 1]; price ASC yields [1, 2, 3].
        $p1 = Post::create(['author_id' => $alice->id, 'status' => 'pending', 'price' => 9.99]);
        $p2 = Post::create(['author_id' => $alice->id, 'status' => 'approved', 'price' => 19.99]);
        $p3 = Post::create(['author_id' => $bob->id, 'status' => 'rejected', 'price' => 29.99]);

        $p1->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Hello world']);
        $p2->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Goodbye world']);
        $p3->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => 'Another post']);

        Post::$trapCalls = 0;
    }

    /** @return list<int> */
    private function ids(DataTable $table): array
    {
        return $table->getData()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function request(string $key, string $direction = 'asc'): Request
    {
        return Request::create('/', 'GET', ['ordering' => ['key' => $key, 'direction' => $direction]]);
    }

    public function testUndeclaredColumnInRequestIsIgnored(): void
    {
        $table = (new DataTable(Post::query(), $this->request('price')))
            ->setColumn('id', '#', orderable: true)
            ->process();

        $this->assertSame([3, 2, 1], $this->ids($table));
        $this->assertSame(['column' => 'id', 'direction' => 'desc'], $table->toArray()['state']['sort']);
    }

    public function testNonOrderableColumnInRequestIsIgnored(): void
    {
        $table = (new DataTable(Post::query(), $this->request('price')))
            ->setColumn('id', '#', orderable: true)
            ->setColumn('price', 'Price', orderable: false)
            ->process();

        $this->assertSame([3, 2, 1], $this->ids($table));
    }

    public function testOrderableColumnInRequestSorts(): void
    {
        $table = (new DataTable(Post::query(), $this->request('price')))
            ->setColumn('id', '#', orderable: true)
            ->setColumn('price', 'Price', orderable: true)
            ->process();

        $this->assertSame([1, 2, 3], $this->ids($table));
        $this->assertSame(['column' => 'price', 'direction' => 'asc'], $table->toArray()['state']['sort']);
    }

    /** @return array<string, array{string}> */
    public static function relationPathProvider(): array
    {
        return [
            'model method on a declared column name'   => ['trap.status'],
            'mutating model method'                    => ['save.status'],
            'model method on a relation column name'   => ['trap.name'],
            'undeclared relation'                      => ['translations.text'],
            'undeclared column of a declared relation' => ['author.id'],
            'nested path'                              => ['trap.author.name'],
        ];
    }

    #[DataProvider('relationPathProvider')]
    public function testRelationPathsNotDeclaredOnARelationColumnAreNeverResolved(string $key): void
    {
        $table = (new DataTable(Post::query(), $this->request($key)))
            ->setColumn('id', '#', orderable: true)
            ->setColumn('status', 'Status', orderable: true)
            ->setRelationColumn(new RelationColumn('author.name', 'Author', orderable: true))
            ->process();

        $this->assertSame(0, Post::$trapCalls);
        $this->assertSame(3, Post::withTrashed()->count());
        $this->assertSame([3, 2, 1], $this->ids($table));
    }

    public function testDeclaredRelationColumnSortsByRelatedColumn(): void
    {
        $build = fn (string $key, string $direction) => (new DataTable(Post::query(), $this->request($key, $direction)))
            ->setColumn('id', '#', orderable: true)
            ->setRelationColumn(new RelationColumn('author.name', 'Author', orderable: true))
            ->process();

        $this->assertSame(3, $this->ids($build('author.name', 'desc'))[0]);
        $this->assertSame(3, $this->ids($build('author.name', 'asc'))[2]);

        // The key a RelationColumn is registered (and serialised) under.
        $this->assertSame(3, $this->ids($build('name', 'desc'))[0]);
    }

    public function testDeclaredRelationColumnThatIsNotOrderableIsIgnored(): void
    {
        $table = (new DataTable(Post::query(), $this->request('author.name', 'desc')))
            ->setColumn('id', '#', orderable: true)
            ->setRelationColumn(new RelationColumn('author.name', 'Author', orderable: false))
            ->process();

        $this->assertSame([3, 2, 1], $this->ids($table));
    }

    public function testOrderableTranslatableColumnSorts(): void
    {
        $table = (new DataTable(Post::query(), $this->request('title', 'desc')))
            ->setColumn('id', '#', orderable: true)
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', orderable: true))
            ->process();

        // "Hello world", "Goodbye world", "Another post"
        $this->assertSame([1, 2, 3], $this->ids($table));
    }

    public function testNonOrderableTranslatableColumnIsIgnored(): void
    {
        $table = (new DataTable(Post::query(), $this->request('title', 'desc')))
            ->setColumn('id', '#', orderable: true)
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', orderable: false))
            ->process();

        $this->assertSame([3, 2, 1], $this->ids($table));
    }

    public function testMalformedOrderingKeyFallsBackToDefault(): void
    {
        $request = Request::create('/', 'GET', ['ordering' => ['key' => ['price'], 'direction' => 'asc']]);

        $table = (new DataTable(Post::query(), $request))
            ->setColumn('id', '#', orderable: true)
            ->setColumn('price', 'Price', orderable: true)
            ->process();

        $this->assertSame([3, 2, 1], $this->ids($table));
    }

    public function testDeveloperSetOrderingIsTrustedEvenForUndeclaredColumns(): void
    {
        $table = (new DataTable(Post::query(), $this->request('id', 'desc')))
            ->setColumn('id', '#', orderable: true)
            ->setOrdering(new Ordering('price', 'ASC'))
            ->process();

        $this->assertSame([1, 2, 3], $this->ids($table));
    }

    public function testCustomDefaultOrderingIsUsedWhenRequestedKeyIsRejected(): void
    {
        $build = function (Request $request) {
            return (new DataTable(Post::query(), $request))
                ->setColumn('id', '#', orderable: true)
                ->setOrdering(Ordering::fromRequest($request, 'price', 'ASC'))
                ->process();
        };

        $this->assertSame([1, 2, 3], $this->ids($build($this->request('status', 'desc'))));
        $this->assertSame([1, 2, 3], $this->ids($build(Request::create('/'))));
        $this->assertSame([3, 2, 1], $this->ids($build($this->request('id', 'desc'))));
    }
}
