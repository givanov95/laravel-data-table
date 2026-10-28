<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\ColumnFilter;
use Givanov95\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An exact match must never hand the database a value its column type rejects:
 * PostgreSQL fails the whole query (22P02, 22003, 22007), where MySQL and SQLite
 * find nothing. Run on every driver in CI.
 */
final class ExactMatchTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Only compiled against, never connected to.
        $app['config']->set('database.connections.fake_pgsql', [
            'driver'   => 'pgsql',
            'host'     => 'db.invalid',
            'database' => 'testing',
            'username' => 'testing',
            'password' => '',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('xm_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->boolean('flag')->default(false);
            $table->date('day')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->integer('cents')->default(0);
            $table->integer('small')->default(0);
            $table->decimal('amount', 8, 2)->default(0);
            $table->string('code');
        });

        XmRecord::create(['uuid' => '0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6e', 'flag' => true, 'day' => '2026-10-28', 'owner_id' => 7, 'cents' => 1999, 'small' => 5, 'amount' => 19.99, 'code' => 'A-1']);
        XmRecord::create(['uuid' => '0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6f', 'flag' => false, 'day' => '2026-10-29', 'owner_id' => 8, 'cents' => 2999, 'small' => 6, 'amount' => 29.99, 'code' => 'B-2']);
    }

    /** @return array<string, array{string}> */
    public static function columns(): array
    {
        return [
            'an integer key'          => ['id'],
            'a uuid'                  => ['uuid'],
            'a boolean cast'          => ['flag'],
            'a date cast'             => ['day'],
            'an integer without cast' => ['owner_id'],
            'an integer cast'         => ['small'],
            'a decimal cast'          => ['amount'],
        ];
    }

    #[DataProvider('columns')]
    public function testAValueTheColumnCannotHoldIsNotAnError(string $column): void
    {
        foreach (['abc', '1.5', '359888123456789012', '3000000000', '28.10.2026'] as $term) {
            $found = $this->search(XmRecord::query(), $column, $term);

            // MySQL turns `flag = 'abc'` into `flag = 0`: a row, but not an error.
            if (self::driver() === 'mysql' && $column === 'flag') {
                continue;
            }

            $this->assertSame([], $found, "{$column} = {$term}");
        }
    }

    public function testAMatchingValueIsStillFound(): void
    {
        $this->assertSame([2], $this->search(XmRecord::query(), 'id', '2'));
        $this->assertSame([2], $this->search(XmRecord::query(), 'owner_id', '8'));
        $this->assertSame([1], $this->search(XmRecord::query(), 'small', '5'));
        $this->assertSame([1], $this->search(XmRecord::query(), 'uuid', '0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6e'));
        $this->assertSame([2], $this->search(XmRecord::query(), 'code', 'B-2'));
    }

    public function testAUuidKeyIsNotAnError(): void
    {
        $this->assertSame([], $this->search(XmUuidRecord::query(), 'uuid', 'abc'));
    }

    public function testAnExactPriceWithALongNumberIsNotAnError(): void
    {
        // A phone number typed in the search box: 18 digits.
        foreach (['cents', 'amount'] as $column) {
            $table = (new DataTable(XmRecord::query(), Request::create('/', 'GET', ['filter' => ['global' => '+359 888 123 456 789 012']])))
                ->setColumn($column, 'Price', searchable: true, exactMatch: true)
                ->setPriceColumn($column)
                ->process();

            $this->assertSame([], $table->getData()->all(), $column);
        }

        $table = (new DataTable(XmRecord::query(), Request::create('/', 'GET', ['filter' => ['global' => '1999']])))
            ->setColumn('cents', 'Price', searchable: true, exactMatch: true)
            ->setPriceColumn('cents')
            ->process();

        $this->assertSame([1], $table->getData()->pluck('id')->all());
    }

    // -- Compiled for PostgreSQL ---------------------------------------------

    public function testOnPostgresAColumnWithoutANumericCastIsComparedAsText(): void
    {
        foreach (['uuid', 'flag', 'day', 'owner_id', 'code'] as $column) {
            $sql = $this->compiled($column, 'abc');

            $this->assertStringContainsString("CAST(\"xm_records\".\"{$column}\" AS TEXT) = ?", $sql, $column);
        }
    }

    public function testOnPostgresANumericCastKeepsTheTypedComparison(): void
    {
        $this->assertStringContainsString('"xm_records"."small" = ?', $this->compiled('small', '5'));
        $this->assertStringContainsString('"xm_records"."id" = ?', $this->compiled('id', '5'));

        // A value it cannot hold never reaches the database.
        $this->assertStringContainsString('1 = 0', $this->compiled('small', '3000000000'));
    }

    /** @return list<int> */
    private function search(Builder $query, string $column, string $term): array
    {
        $table = (new DataTable($query, Request::create('/', 'GET', ['filter' => ['global' => $term]])))
            ->setColumn($column, 'Column', searchable: true, exactMatch: true)
            ->process();

        return $table->getData()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function compiled(string $column, string $value): string
    {
        $table = (new DataTable(XmRecord::on('fake_pgsql'), Request::create('/')))
            ->setColumn($column, 'Column', searchable: true, exactMatch: true);

        return (new ColumnFilter($table))->apply(XmRecord::on('fake_pgsql'), $column, $value)->getBuilder()->toSql();
    }
}

class XmRecord extends Model
{
    protected $table = 'xm_records';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'flag'   => 'boolean',
        'day'    => 'date',
        'small'  => 'integer',
        'amount' => 'decimal:2',
    ];
}

class XmUuidRecord extends Model
{
    use HasUuids;

    protected $table = 'xm_records';

    protected $primaryKey = 'uuid';

    protected $guarded = [];

    public $timestamps = false;
}
