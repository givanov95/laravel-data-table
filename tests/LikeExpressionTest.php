<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Support\LikeExpression;
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
 * server (or driver extension) is needed to cover every dialect.
 */
final class LikeExpressionTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function dialects(): array
    {
        return [
            // Backslash is already the escape character here; a literal ESCAPE '\' would be an
            // unterminated string in MySQL.
            'mysql'   => ['mysql', '`posts`.`title` LIKE ?'],
            'mariadb' => ['mariadb', '`posts`.`title` LIKE ?'],
            // Same ::text cast the grammar adds to a LIKE, so numeric columns stay searchable.
            'pgsql' => ['pgsql', '"posts"."title"::text LIKE ?'],
            // No default escape character.
            'sqlite' => ['sqlite', '"posts"."title" LIKE ? ESCAPE \'\\\''],
            'sqlsrv' => ['sqlsrv', '[posts].[title] LIKE ? ESCAPE \'\\\''],
        ];
    }

    #[DataProvider('dialects')]
    public function testBuildsTheDriversOwnExpression(string $driver, string $sql): void
    {
        $expression = LikeExpression::contains(self::connection($driver), 'posts.title', 'abc');

        $this->assertSame($sql, $expression->sql);
        $this->assertSame('%abc%', $expression->pattern);
    }

    /** @return array<string, array{string, string}> */
    public static function inputs(): array
    {
        return [
            'plain text'         => ['abc', '%abc%'],
            'percent'            => ['50%', '%50\\%%'],
            'underscore'         => ['a_b', '%a\\_b%'],
            'backslash'          => ['C:\\dir', '%C:\\\\dir%'],
            'all three'          => ['%_\\', '%\\%\\_\\\\%'],
            'only a percent'     => ['%', '%\\%%'],
            'escape comes first' => ['\\%', '%\\\\\\%%'],
            'multibyte'          => ['Здравей_свят', '%Здравей\\_свят%'],
        ];
    }

    #[DataProvider('inputs')]
    public function testEscapesLikeWildcardsInTheValue(string $input, string $pattern): void
    {
        $expression = LikeExpression::contains(self::connection('sqlite'), 'posts.title', $input);

        $this->assertSame($pattern, $expression->pattern);
    }

    public function testEscapeIsUsableOnItsOwn(): void
    {
        $this->assertSame('100\\%\\_\\\\', LikeExpression::escape('100%_\\'));
    }

    public function testQuotesIdentifiersThroughTheGrammarAndAppliesTheTablePrefix(): void
    {
        $expression = LikeExpression::contains(self::connection('mysql', prefix: 'app_'), 'posts.title', 'abc');

        $this->assertSame('`app_posts`.`title` LIKE ?', $expression->sql);
    }

    public function testAnIdentifierCannotBreakOutOfItsQuotes(): void
    {
        $expression = LikeExpression::contains(self::connection('mysql'), 'posts.title`) OR 1=1 -- ', 'abc');

        $this->assertSame('`posts`.`title``) OR 1=1 -- ` LIKE ?', $expression->sql);
    }

    public function testTheValueIsNeverPartOfTheSql(): void
    {
        $expression = LikeExpression::contains(self::connection('sqlite'), 'posts.title', "x' OR '1'='1");

        $this->assertStringNotContainsString('OR', $expression->sql);
        $this->assertSame("%x' OR '1'='1%", $expression->pattern);
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
