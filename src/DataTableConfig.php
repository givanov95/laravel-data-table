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
    /**
     * @deprecated 4.0 Not read any more: the table comes from the translations relation.
     */
    public static function getTranslatableTable(): string
    {
        return (string) Config::get('data-table.translatable_table', 'translations');
    }

    public static function getTranslatableColumnName(): string
    {
        return (string) Config::get('data-table.translatable_column', 'key');
    }

    /** The model's relation (morphMany) to its translations. */
    public static function getTranslatableRelation(): string
    {
        return (string) Config::get('data-table.translatable_relation', 'translations');
    }

    public static function getTranslatableLocaleColumn(): string
    {
        return (string) Config::get('data-table.translatable_locale_column', 'locale');
    }

    public static function getTranslatableTextColumn(): string
    {
        return (string) Config::get('data-table.translatable_text_column', 'text');
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

    /**
     * The parameter that carries the filters by column: `filter[status]=paid`
     * under the default `filter`.
     */
    public static function getColumnFiltersKey(): string
    {
        return (string) Config::get('data-table.column_filters', 'filter');
    }

    /**
     * The column keys a filter cannot have: the other request keys that sit
     * under the column filters' parameter (`filter.global` → `global`), so
     * `filter[global]` stays the search.
     *
     * @return list<string>
     */
    public static function reservedFilterNames(): array
    {
        $prefix = self::getColumnFiltersKey().'.';
        $names = [];

        foreach ([self::getGlobalFilterKey(), self::getTrashedKey(), self::getTimeZoneKey()] as $key) {
            if (str_starts_with($key, $prefix)) {
                $names[] = explode('.', substr($key, strlen($prefix)))[0];
            }
        }

        return array_values(array_unique($names));
    }

    public static function getOrderingKey(): string
    {
        return (string) Config::get('data-table.ordering', 'ordering');
    }

    /**
     * The request keys a frontend has to send (`toArray()`'s `params`), so a
     * changed key in config/data-table.php reaches the composable too.
     *
     * @return array{per_page: string, global_filter: string, trashed: string, ordering: string, time_zone: string, column_filters: string}
     */
    public static function requestKeys(): array
    {
        return [
            'per_page' => self::getPerPageKey(),
            'global_filter' => self::getGlobalFilterKey(),
            'trashed' => self::getTrashedKey(),
            'ordering' => self::getOrderingKey(),
            'time_zone' => self::getTimeZoneKey(),
            'column_filters' => self::getColumnFiltersKey(),
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
