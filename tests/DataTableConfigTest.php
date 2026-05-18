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
        $this->assertSame('translations', DataTableConfig::getTranslatableTable());
        $this->assertSame('key', DataTableConfig::getTranslatableColumnName());
        $this->assertSame('filter.global', DataTableConfig::getGlobalFilterKey());
        $this->assertSame('perPage', DataTableConfig::getPerPageKey());
        $this->assertSame('filter.trashed', DataTableConfig::getTrashedKey());
        $this->assertSame('restore_id', DataTableConfig::getRestoreIdKey());
        $this->assertSame('ordering', DataTableConfig::getOrderingKey());
        $this->assertSame(15, DataTableConfig::getDefaultPerPage());
    }

    public function testRespectsOverrides(): void
    {
        Config::set('data-table.translatable_table', 'i18n');
        Config::set('data-table.per_page', 'pageSize');
        Config::set('data-table.default_per_page', 50);

        $this->assertSame('i18n', DataTableConfig::getTranslatableTable());
        $this->assertSame('pageSize', DataTableConfig::getPerPageKey());
        $this->assertSame(50, DataTableConfig::getDefaultPerPage());
    }
}
