<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\DataTableParams;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

final class DataTableParamsTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int|null}>
     */
    public static function restoreIdProvider(): array
    {
        return [
            'a plain id'               => ['7', 7],
            'no id'                    => [null, null],
            'text'                     => ['abc', null],
            'an id with trailing text' => ['12abc', null],
            // (int) ['x'] is 1, which would restore the row with id 1.
            'an array'       => [['x'], null],
            'an empty array' => [[], null],
        ];
    }

    #[DataProvider('restoreIdProvider')]
    public function testRestoreIdIsOnlyTakenFromAWholeNumber(mixed $requested, ?int $expected): void
    {
        $request = Request::create('/', 'GET', $requested === null ? [] : ['restore_id' => $requested]);

        $this->assertSame($expected, DataTableParams::fromRequest($request)->restoreId);
    }

    public function testAnArrayAsTheSearchOrTheTrashedToggleIsTreatedAsMissing(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => ['a'], 'trashed' => ['b']]]);

        $params = DataTableParams::fromRequest($request);

        $this->assertNull($params->globalFilter);
        $this->assertNull($params->trashed);
    }

    public function testANumberInAJsonBodyIsReadAsText(): void
    {
        $request = Request::create(
            '/',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"filter":{"global":123,"trashed":true}}',
        );

        $params = DataTableParams::fromRequest($request);

        $this->assertSame('123', $params->globalFilter);
        $this->assertSame('1', $params->trashed);
    }

    public function testStringsAreKept(): void
    {
        $request = Request::create('/', 'GET', ['filter' => ['global' => 'red', 'trashed' => 'true']]);

        $params = DataTableParams::fromRequest($request);

        $this->assertSame('red', $params->globalFilter);
        $this->assertSame('true', $params->trashed);
    }
}
