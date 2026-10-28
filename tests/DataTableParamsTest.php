<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\DataTableParams;
use Illuminate\Http\Request;

final class DataTableParamsTest extends TestCase
{
    public function testRestoreIdIsNoLongerAParam(): void
    {
        $this->assertFalse(property_exists(DataTableParams::class, 'restoreId'));
    }

    public function testTheTimeZoneIsOneKnownToPhpOrNothing(): void
    {
        $zone = fn (mixed $value) => DataTableParams::fromRequest(
            Request::create('/', 'GET', ['filter' => ['timeZone' => $value]]),
        )->timeZone;

        $this->assertSame('Europe/Sofia', $zone('Europe/Sofia'));
        $this->assertSame('Etc/UTC', $zone('Etc/UTC'));
        $this->assertSame('+02:00', $zone('+02:00'));
        $this->assertNull($zone('xx'));
        $this->assertNull($zone(''));
        $this->assertNull($zone(['Europe/Sofia']));
        $this->assertNull(DataTableParams::fromRequest(Request::create('/'))->timeZone);
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
