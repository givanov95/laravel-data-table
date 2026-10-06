<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\DataTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs on a real MySQL only (`DT_DB=mysql`), the one place where the sql_mode
 * changes what a LIKE means.
 */
final class MysqlSqlModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (self::driver() !== 'mysql') {
            $this->markTestSkipped('Needs a real MySQL: run with DT_DB=mysql.');
        }

        Schema::create('mode_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        ModeArticle::create(['title' => 'a_c']);
        ModeArticle::create(['title' => 'abc']);
        ModeArticle::create(['title' => 'back\\slash']);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function sqlModes(): array
    {
        return [
            'the default mode' => [null],
            // `\` is no longer the default LIKE escape character here.
            'NO_BACKSLASH_ESCAPES' => ['NO_BACKSLASH_ESCAPES'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sqlModes')]
    public function testWildcardsAndBackslashesAreLiteralInEverySqlMode(?string $mode): void
    {
        if ($mode !== null) {
            DB::statement("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, '{$mode}')");
        }

        $this->assertSame(['a_c'], $this->search('_'));
        $this->assertSame(['a_c'], $this->search('a_c'));
        $this->assertSame([], $this->search('a%c'));
        $this->assertSame(['back\\slash'], $this->search('\\'));
    }

    /**
     * @return list<string>
     */
    private function search(string $text): array
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => $text]]);

        $table = (new DataTable(ModeArticle::query(), $request))
            ->setColumn('title', 'Title', searchable: true)
            ->process();

        return $table->getData()->pluck('title')->all();
    }
}

class ModeArticle extends Model
{
    protected $table = 'mode_articles';

    protected $guarded = [];

    public $timestamps = false;
}
