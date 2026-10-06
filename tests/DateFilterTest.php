<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\ColumnFilter;
use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\Exceptions\UnsupportedDriverException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class DateFilterTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Never connected to: the connections are only used to compile SQL.
        foreach (['mysql', 'pgsql', 'sqlsrv'] as $driver) {
            $app['config']->set("database.connections.{$driver}", [
                'driver'   => $driver,
                'host'     => 'db.invalid',
                'database' => 'testing',
                'username' => 'testing',
                'password' => '',
                'prefix'   => '',
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->timestamp('opened_at')->nullable();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->nullable();
            $table->timestamp('starts_at')->nullable();
        });

        $oct5 = DateFilterVenue::create(['opened_at' => '2026-10-05 08:00:00']);
        $nov1 = DateFilterVenue::create(['opened_at' => '2026-11-01 20:15:00']);

        DateFilterEvent::create(['venue_id' => $oct5->id, 'starts_at' => '2026-10-05 13:45:30']);
        DateFilterEvent::create(['venue_id' => $oct5->id, 'starts_at' => '2026-10-05 08:00:00']);
        DateFilterEvent::create(['venue_id' => $nov1->id, 'starts_at' => '2026-10-06 13:45:30']);
    }

    // -- Executed for real, on SQLite ----------------------------------------

    public function testFiltersByDateOnSqlite(): void
    {
        $ids = $this->filterIds(DateFilterEvent::query(), 'starts_at', '05.10.2026', 'd.m.Y');

        $this->assertSame([1, 2], $ids);
    }

    public function testFiltersByTimeWithSecondsOnSqlite(): void
    {
        $ids = $this->filterIds(DateFilterEvent::query(), 'starts_at', '13:45:30', 'H:i:s');

        $this->assertSame([1, 3], $ids);
    }

    public function testFiltersByTimeWithoutSecondsOnSqlite(): void
    {
        $ids = $this->filterIds(DateFilterEvent::query(), 'starts_at', '08:00', 'H:i');

        $this->assertSame([2], $ids);
    }

    public function testFiltersByDayAndMonthOnSqlite(): void
    {
        $ids = $this->filterIds(DateFilterEvent::query(), 'starts_at', '06.10', 'd.m');

        $this->assertSame([3], $ids);
    }

    public function testExactMatchComparesWholeFormattedValue(): void
    {
        $table = $this->table(DateFilterEvent::query(), 'starts_at', 'd.m.Y', exactMatch: true);
        $builder = (new ColumnFilter($table))->apply(DateFilterEvent::query(), 'starts_at', '05.10.2026')->getBuilder();

        $this->assertStringContainsString('= ?', $builder->toSql());
        $this->assertSame([1, 2], $builder->orderBy('id')->pluck('id')->all());
    }

    public function testFiltersByDateOfARelationOnSqlite(): void
    {
        $table = $this->relationTable(DateFilterEvent::query(), 'd.m.Y');
        $builder = (new ColumnFilter($table))->apply(DateFilterEvent::query(), 'opened_at', '01.11.2026')->getBuilder();

        $this->assertSame([3], $builder->orderBy('id')->pluck('id')->all());
    }

    public function testGlobalSearchOnADateColumnWorksOnSqlite(): void
    {
        $table = (new DataTable(
            DateFilterEvent::query(),
            Request::create('/', 'GET', ['filter' => ['global' => '06.10.2026']]),
        ))
            ->setColumn('starts_at', 'Starts', searchable: true)
            ->setDateColumn('starts_at', 'd.m.Y')
            ->process();

        $this->assertCount(1, $table->getData());
    }

    // -- Compiled only, for the drivers without a server in the test run ------

    /** @return array<string, array{string, string, string}> */
    public static function compiledSql(): array
    {
        return [
            'mysql'  => ['mysql', 'where (DATE_FORMAT(`events`.`starts_at`, ?) LIKE ?)', '%d.%m.%Y'],
            'pgsql'  => ['pgsql', 'where (TO_CHAR("events"."starts_at", ?) LIKE ?)', 'DD.MM.YYYY'],
            'sqlite' => ['sqlite', 'where (strftime(?, "events"."starts_at") LIKE ?)', '%d.%m.%Y'],
        ];
    }

    #[DataProvider('compiledSql')]
    public function testCompilesTheDriversOwnDateExpression(string $connection, string $expectedWhere, string $expectedFormat): void
    {
        $builder = $this->filterBuilder(DateFilterEvent::on($connection), 'starts_at', '05.10.2026', 'd.m.Y');

        $this->assertStringContainsString($expectedWhere, $builder->toSql());
        $this->assertSame([$expectedFormat, '%05.10.2026%'], $builder->getBindings());
    }

    #[DataProvider('compiledSql')]
    public function testOrWhereKeepsTheDriversExpression(string $connection, string $expectedWhere, string $expectedFormat): void
    {
        $table = $this->table(DateFilterEvent::on($connection), 'starts_at', 'd.m.Y');
        $builder = (new ColumnFilter($table))
            ->apply(DateFilterEvent::on($connection)->where('id', 1), 'starts_at', '05.10.2026', useOrWhere: true)
            ->getBuilder();

        $this->assertStringContainsString('or '.substr($expectedWhere, strlen('where ')), $builder->toSql());
        $this->assertSame([1, $expectedFormat, '%05.10.2026%'], $builder->getBindings());
    }

    public function testRelationDateFilterUsesTheRealTableOfTheRelatedModel(): void
    {
        $table = $this->relationTable(DateFilterEvent::on('pgsql'), 'd.m.Y');
        $builder = (new ColumnFilter($table))->apply(DateFilterEvent::on('pgsql'), 'opened_at', '01.11.2026')->getBuilder();

        // `venues`, not the snake-cased relation name `venue`.
        $this->assertStringContainsString('TO_CHAR("venues"."opened_at", ?) LIKE ?', $builder->toSql());
        $this->assertSame(['DD.MM.YYYY', '%01.11.2026%'], $builder->getBindings());
    }

    public function testRelationDateFilterIsCompiledForMysqlToo(): void
    {
        $table = $this->relationTable(DateFilterEvent::on('mysql'), 'd.m.Y');
        $builder = (new ColumnFilter($table))->apply(DateFilterEvent::on('mysql'), 'opened_at', '01.11.2026')->getBuilder();

        $this->assertStringContainsString('DATE_FORMAT(`venues`.`opened_at`, ?) LIKE ?', $builder->toSql());
    }

    public function testUnsupportedDriverFailsWithAClearException(): void
    {
        $this->expectException(UnsupportedDriverException::class);
        $this->expectExceptionMessage("'sqlsrv'");

        $this->filterBuilder(DateFilterEvent::on('sqlsrv'), 'starts_at', '05.10.2026', 'd.m.Y');
    }

    // -- Helpers --------------------------------------------------------------

    /** @return list<int> */
    private function filterIds(Builder $builder, string $column, string $value, string $format): array
    {
        return $this->filterBuilder($builder, $column, $value, $format)->orderBy('id')->pluck('id')->all();
    }

    private function filterBuilder(Builder $builder, string $column, string $value, string $format): Builder
    {
        $table = $this->table($builder, $column, $format);

        return (new ColumnFilter($table))->apply($builder, $column, $value)->getBuilder();
    }

    private function table(Builder $builder, string $column, string $format, bool $exactMatch = false): DataTable
    {
        return (new DataTable($builder, Request::create('/')))
            ->setColumn($column, 'Date', searchable: true, exactMatch: $exactMatch)
            ->setDateColumn($column, $format);
    }

    private function relationTable(Builder $builder, string $format): DataTable
    {
        return (new DataTable($builder, Request::create('/')))
            ->setRelationColumn(new RelationColumn('venue.opened_at', 'Opened', searchable: true))
            ->setDateColumn('opened_at', $format);
    }
}

class DateFilterEvent extends Model
{
    protected $table = 'events';

    protected $guarded = [];

    public $timestamps = false;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(DateFilterVenue::class, 'venue_id');
    }
}

class DateFilterVenue extends Model
{
    protected $table = 'venues';

    protected $guarded = [];

    public $timestamps = false;
}
