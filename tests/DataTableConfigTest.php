<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\DataTableConfig;
use Illuminate\Support\Facades\Config;

final class DataTableConfigTest extends TestCase
{
    public function testReturnsDefaultsWhenUnset(): void
    {
        // Service provider already merged the package config — these are the defaults.
        $this->assertSame('translations', DataTableConfig::getTranslatableRelation());
        $this->assertSame('key', DataTableConfig::getTranslatableColumnName());
        $this->assertSame('locale', DataTableConfig::getTranslatableLocaleColumn());
        $this->assertSame('text', DataTableConfig::getTranslatableTextColumn());
        $this->assertSame('filter.global', DataTableConfig::getGlobalFilterKey());
        $this->assertSame('perPage', DataTableConfig::getPerPageKey());
        $this->assertSame('filter.trashed', DataTableConfig::getTrashedKey());
        $this->assertSame('ordering', DataTableConfig::getOrderingKey());
        $this->assertSame('filter.timeZone', DataTableConfig::getTimeZoneKey());
        $this->assertSame(15, DataTableConfig::getDefaultPerPage());
        $this->assertSame(100, DataTableConfig::getMaxPerPage());
    }

    public function testRespectsOverrides(): void
    {
        Config::set('data-table.translatable_relation', 'i18n');
        Config::set('data-table.per_page', 'pageSize');
        Config::set('data-table.default_per_page', 50);
        Config::set('data-table.max_per_page', 250);

        $this->assertSame('i18n', DataTableConfig::getTranslatableRelation());
        $this->assertSame('pageSize', DataTableConfig::getPerPageKey());
        $this->assertSame(50, DataTableConfig::getDefaultPerPage());
        $this->assertSame(250, DataTableConfig::getMaxPerPage());
    }

    public function testTheTimeZoneKeySitsNextToTheSearchKeyUnlessSet(): void
    {
        Config::set('data-table.global_filter', 'q');
        $this->assertSame('timeZone', DataTableConfig::getTimeZoneKey());

        Config::set('data-table.global_filter', 'search.term');
        $this->assertSame('search.timeZone', DataTableConfig::getTimeZoneKey());

        Config::set('data-table.time_zone', 'tz');
        $this->assertSame('tz', DataTableConfig::getTimeZoneKey());
        $this->assertSame('tz', DataTableConfig::requestKeys()['time_zone']);
    }
}
