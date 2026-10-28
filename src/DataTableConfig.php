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

    /**
     * The key of the browser's time zone, sent with the search. By default it
     * sits next to the search key: `filter.global` → `filter.timeZone`, `q` →
     * `timeZone`.
     */
    public static function getTimeZoneKey(): string
    {
        $configured = Config::get('data-table.time_zone');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $parent = array_slice(explode('.', self::getGlobalFilterKey()), 0, -1);

        return implode('.', [...$parent, 'timeZone']);
    }

    public static function getOrderingKey(): string
    {
        return (string) Config::get('data-table.ordering', 'ordering');
    }

    /**
     * The request keys a frontend has to send (`toArray()`'s `params`), so a
     * changed key in config/data-table.php reaches the composable too.
     *
     * @return array{per_page: string, global_filter: string, trashed: string, ordering: string, time_zone: string}
     */
    public static function requestKeys(): array
    {
        return [
            'per_page' => self::getPerPageKey(),
            'global_filter' => self::getGlobalFilterKey(),
            'trashed' => self::getTrashedKey(),
            'ordering' => self::getOrderingKey(),
            'time_zone' => self::getTimeZoneKey(),
        ];
    }

    public static function getDefaultPerPage(): int
    {
        return (int) Config::get('data-table.default_per_page', 15);
    }

    public static function getMaxPerPage(): int
    {
        return (int) Config::get('data-table.max_per_page', 100);
    }
}
