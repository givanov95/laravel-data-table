<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Exceptions\UnsupportedDriverException;
use Givanov95\DataTable\Support\DateSqlExpression;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure SQL-generation tests: the connections never open a PDO, so no database
 * server (or driver extension) is needed to cover mysql, mariadb and pgsql.
 */
final class DateSqlExpressionTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function dialects(): array
    {
        return [
            'mysql date'      => ['mysql', 'd.m.Y', 'DATE_FORMAT(`events`.`starts_at`, ?)', '%d.%m.%Y'],
            'mysql time'      => ['mysql', 'H:i:s', 'DATE_FORMAT(`events`.`starts_at`, ?)', '%H:%i:%s'],
            'mariadb date'    => ['mariadb', 'd.m.Y', 'DATE_FORMAT(`events`.`starts_at`, ?)', '%d.%m.%Y'],
            'pgsql date'      => ['pgsql', 'd.m.Y', 'TO_CHAR("events"."starts_at", ?)', 'DD.MM.YYYY'],
            'pgsql time'      => ['pgsql', 'H:i:s', 'TO_CHAR("events"."starts_at", ?)', 'HH24:MI:SS'],
            'pgsql day-month' => ['pgsql', 'd/m', 'TO_CHAR("events"."starts_at", ?)', 'DD/MM'],
            'sqlite date'     => ['sqlite', 'd.m.Y', 'strftime(?, "events"."starts_at")', '%d.%m.%Y'],
            'sqlite time'     => ['sqlite', 'H:i:s', 'strftime(?, "events"."starts_at")', '%H:%M:%S'],
        ];
    }

    #[DataProvider('dialects')]
    public function testBuildsTheDriversOwnExpression(string $driver, string $phpFormat, string $sql, string $format): void
    {
        $expression = DateSqlExpression::make(self::connection($driver), 'events.starts_at', $phpFormat);

        $this->assertSame($sql, $expression->sql);
        $this->assertSame($format, $expression->format);
    }

    public function testQuotesIdentifiersThroughTheGrammarAndAppliesTheTablePrefix(): void
    {
        $expression = DateSqlExpression::make(self::connection('mysql', prefix: 'app_'), 'events.starts_at', 'd.m.Y');

        $this->assertSame('DATE_FORMAT(`app_events`.`starts_at`, ?)', $expression->sql);
    }

    public function testAnIdentifierCannotBreakOutOfItsQuotes(): void
    {
        $expression = DateSqlExpression::make(self::connection('mysql'), 'events.starts_at`) OR 1=1 -- ', 'd.m.Y');

        $this->assertSame('DATE_FORMAT(`events`.`starts_at``) OR 1=1 -- `, ?)', $expression->sql);
    }

    public function testQuotesNonPunctuationDelimitersForPostgres(): void
    {
        // A delimiter that is a TO_CHAR pattern letter must be copied, not interpreted.
        $expression = DateSqlExpression::make(self::connection('pgsql'), 'events.starts_at', 'd\\Dm');

        $this->assertSame('DD"\\\\""D"MM', $expression->format);
    }

    public function testEscapesPercentSignsInLiteralsForMysqlAndSqlite(): void
    {
        $this->assertSame('%d%%%m', DateSqlExpression::make(self::connection('mysql'), 'e.c', 'd%m')->format);
        $this->assertSame('%d%%%m', DateSqlExpression::make(self::connection('sqlite'), 'e.c', 'd%m')->format);
    }

    public function testRejectsDriversItCannotFormatDatesFor(): void
    {
        $this->expectException(UnsupportedDriverException::class);
        $this->expectExceptionMessage("'sqlsrv'");

        DateSqlExpression::make(self::connection('sqlsrv'), 'events.starts_at', 'd.m.Y');
    }

    private static function connection(string $driver, string $prefix = ''): Connection
    {
        // Throwing resolver: proves the SQL is built without ever touching the database.
        $pdo = static fn () => throw new LogicException('The database must not be reached.');

        $class = match ($driver) {
            'mysql', 'mariadb' => MySqlConnection::class,
            'pgsql'            => PostgresConnection::class,
            'sqlite'           => SQLiteConnection::class,
            'sqlsrv'           => SqlServerConnection::class,
        };

        return new $class($pdo, 'testing', $prefix, ['driver' => $driver, 'name' => 'testing']);
    }
}
