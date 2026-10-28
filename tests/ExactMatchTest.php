<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\Support\PostgresColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An exact match must never hand the database a value its column type rejects:
 * PostgreSQL fails the whole query (22P02, 22003, 22007), where MySQL and SQLite
 * find nothing. Run on every driver in CI.
 */
final class ExactMatchTest extends TestCase
{
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
            $table->decimal('total', 8, 2)->default(0);
            $table->decimal('price_raw', 10, 2)->default(0);
            $table->timestamp('at')->nullable();
            $table->softDeletes();
        });

        // Through the query builder: the stored form is what the test says, not a cast's.
        DB::table('xm_records')->insert([
            ['uuid' => '0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6e', 'flag' => true, 'day' => '2026-10-28', 'owner_id' => 7, 'cents' => 1999, 'small' => 5, 'amount' => 19.99, 'code' => 'A-1', 'total' => 19.99, 'price_raw' => 1999, 'at' => '2026-10-28 10:00:00'],
            ['uuid' => '0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6f', 'flag' => false, 'day' => '2026-10-29', 'owner_id' => 8, 'cents' => 2999, 'small' => 6, 'amount' => 29.99, 'code' => 'B-2', 'total' => 29.99, 'price_raw' => 2999, 'at' => '2026-10-29 11:00:00'],
        ]);
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
            'a decimal without cast'  => ['total'],
            'a timestamp'             => ['at'],
        ];
    }

    #[DataProvider('columns')]
    public function testAValueTheColumnCannotHoldIsNotAnError(string $column): void
    {
        foreach (['abc', '1.5', '359888123456789012', '3000000000', '28.10.2026'] as $term) {
            $found = $this->search(XmRecord::query(), $column, $term);

            // MySQL turns `flag = 'abc'` into `flag = 0`: a row, but not an error.
            if (self::driver() === 'mysql' && $column === 'flag') {
                $this->addToAssertionCount(1);

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

        // As the database compares them, not as text: '1' is true, 19.99 is 19.99.
        $this->assertSame([1], $this->search(XmRecord::query(), 'flag', '1'));
        $this->assertSame([1], $this->search(XmRecord::query(), 'total', '19.99'));

        if (self::driver() !== 'sqlite') {
            // SQLite compares the stored text.
            $this->assertSame([1], $this->search(XmRecord::query(), 'at', '2026-10-28 10:00'));
            $this->assertSame([2], $this->search(XmRecord::query(), 'day', '2026-10-29'));
        }
    }

    public function testRestoreOfAnIdThatCannotBeTheKeyIsNotFound(): void
    {
        XmUuidRecord::query()->update(['deleted_at' => '2026-10-28 12:00:00']);
        Gate::define('restore', fn (?object $user): bool => true);

        $this->expectException(ModelNotFoundException::class);

        // On PostgreSQL `uuid = 'abc'` would be a 22P02 (a 500), not a 404.
        (new DataTable(XmUuidRecord::query(), Request::create('/', 'POST')))->allowTrashed()->restore('abc');
    }

    public function testAUuidKeyIsNotAnError(): void
    {
        $this->assertSame([], $this->search(XmUuidRecord::query(), 'uuid', 'abc'));
    }

    public function testAnExactPriceWithALongNumberIsNotAnError(): void
    {
        // A phone number typed in the search box: 18 digits.
        foreach (['cents', 'amount', 'price_raw'] as $column) {
            $table = (new DataTable(XmRecord::query(), Request::create('/', 'GET', ['filter' => ['global' => '+359 888 123 456 789 012']])))
                ->setColumn($column, 'Price', searchable: true, exactMatch: true)
                ->setPriceColumn($column)
                ->process();

            $this->assertSame([], $table->getData()->all(), $column);
        }

        foreach (['cents', 'price_raw'] as $column) {
            $table = (new DataTable(XmRecord::query(), Request::create('/', 'GET', ['filter' => ['global' => '1999']])))
                ->setColumn($column, 'Price', searchable: true, exactMatch: true)
                ->setPriceColumn($column)
                ->process();

            // 1999.00 is 1999 for the database.
            $this->assertSame([1], $table->getData()->pluck('id')->map(fn ($id) => (int) $id)->all(), $column);
        }
    }

    /** @return array<string, array{string, string, bool|null}> */
    public static function postgresLiterals(): array
    {
        return [
            'int4 in range'        => ['int4', '2147483647', true],
            'int4 past 32 bits'    => ['int4', '2147483648', false],
            'int2 past 16 bits'    => ['int2', '40000', false],
            'int8 past 64 bits'    => ['int8', '9223372036854775808', false],
            'int8 text'            => ['int8', 'abc', false],
            'int4 decimal'         => ['int4', '1.5', false],
            'numeric'              => ['numeric', '1.5', true],
            'numeric long'         => ['numeric', '359888123456789012', true],
            'numeric text'         => ['numeric', 'abc', false],
            'bool one'             => ['bool', '1', true],
            'bool word'            => ['bool', 'TRUE', true],
            'bool text'            => ['bool', 'abc', false],
            'uuid'                 => ['uuid', '0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6e', true],
            'uuid text'            => ['uuid', 'abc', false],
            'date'                 => ['date', '2026-10-28', true],
            'date local form'      => ['date', '28.10.2026', false],
            'date that is not one' => ['date', '2026-02-31', false],
            'timestamp minute'     => ['timestamptz', '2026-10-28 10:00', true],
            'time'                 => ['time', '10:00:30', true],
            'varchar'              => ['varchar', 'anything', true],
            'jsonb'                => ['jsonb', '{}', null],
            'int4 leading zeros'   => ['int4', '007', true],
            'int4 negative bound'  => ['int4', '-2147483648', true],
            'int4 spaces'          => ['int4', ' 5 ', true],
            'float4 too large'     => ['float4', '1e50', false],
            'float8 underflow'     => ['float8', '1e-400', false],
            'float8 zero'          => ['float8', '0.0e5', true],
            'uuid without hyphens' => ['uuid', '0199F3B46C1E7D2A9B8E1F2A3B4C5D6E', true],
            'uuid in braces'       => ['uuid', '{0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6e}', true],
            'uuid with a space'    => ['uuid', ' 0199f3b4-6c1e-7d2a-9b8e-1f2a3b4c5d6e', false],
            'year 0'               => ['date', '0000-01-01', false],
            'timestamp with T'     => ['timestamp', '2026-10-28T10:00:00', true],
            'timestamp fraction'   => ['timestamptz', '2026-10-28 10:00:00.5+02:00', true],
            'end of the day'       => ['time', '24:00', true],
            'past the end'         => ['time', '24:01', false],
            'a time in a DST gap'  => ['timestamp', '2026-03-29 03:30', true],
        ];
    }

    #[DataProvider('postgresLiterals')]
    public function testKnowsWhichLiteralsPostgresTakes(string $type, string $value, ?bool $expected): void
    {
        $this->assertSame($expected, PostgresColumns::accepts($type, $value));
    }

    /** @return list<int> */
    private function search(Builder $query, string $column, string $term): array
    {
        $table = (new DataTable($query, Request::create('/', 'GET', ['filter' => ['global' => $term]])))
            ->setColumn($column, 'Column', searchable: true, exactMatch: true)
            ->process();

        return $table->getData()->pluck('id')->map(fn ($id) => (int) $id)->all();
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
    use SoftDeletes;

    protected $table = 'xm_records';

    protected $primaryKey = 'uuid';

    protected $guarded = [];

    public $timestamps = false;
}
