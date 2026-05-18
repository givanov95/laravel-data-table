<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Support\Facades\Config;

/**
 * Strongly-typed accessor for the package config so callers don't have to
 * remember the dotted keys.
 */
class DataTableConfig
{
    public static function getTranslatableTable(): string
    {
        return (string) Config::get('data-table.translatable_table', 'translations');
    }

    public static function getTranslatableColumnName(): string
    {
        return (string) Config::get('data-table.translatable_column', 'key');
    }

    public static function getGlobalFilterKey(): string
    {
        return (string) Config::get('data-table.global_filter', 'filter.global');
    }

    public static function getPerPageKey(): string
    {
        return (string) Config::get('data-table.per_page', 'perPage');
    }

    public static function getTrashedKey(): string
    {
        return (string) Config::get('data-table.trashed', 'filter.trashed');
    }

    public static function getRestoreIdKey(): string
    {
        return (string) Config::get('data-table.restore_id', 'restore_id');
    }

    public static function getOrderingKey(): string
    {
        return (string) Config::get('data-table.ordering', 'ordering');
    }

    public static function getDefaultPerPage(): int
    {
        return (int) Config::get('data-table.default_per_page', 15);
    }
}
