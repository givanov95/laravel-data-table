<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Translatable storage
    |--------------------------------------------------------------------------
    |
    | Used by TranslatableColumn. `translatable_table` is the table that stores
    | translations, `translatable_column` is the column inside that table that
    | holds the translation key (e.g. "title", "name").
    |
    */
    'translatable_table'  => 'translations',
    'translatable_column' => 'key',

    /*
    |--------------------------------------------------------------------------
    | Request parameter keys
    |--------------------------------------------------------------------------
    |
    | DataTable and QueryBuilderTable read incoming HTTP parameters using these
    | keys. Override them here if your frontend uses different conventions;
    | the Vue components bundled with the package send the defaults below.
    |
    | `ordering` is the parameter that carries `key` and `direction`
    | (`ordering[key]`, `ordering[direction]`). `restore_id` is only read by
    | DataTable. QueryBuilderTable takes the search and trashed keys from
    | anywhere in the request; when they sit under Spatie's filter parameter
    | (`filter.search`), that filter is accepted without being applied by Spatie.
    |
    */
    'global_filter' => 'filter.global',
    'per_page'      => 'perPage',
    'trashed'       => 'filter.trashed',
    'restore_id'    => 'restore_id',
    'ordering'      => 'ordering',

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'default_per_page' => 15,

    /*
    |--------------------------------------------------------------------------
    | Maximum rows per page
    |--------------------------------------------------------------------------
    |
    | Hard upper bound for the per-page value read from the request, so a
    | crafted `?perPage=100000` cannot load the whole table at once. Applies to
    | both DataTable and QueryBuilderTable. A requested value that is below 1
    | or not a number falls back to `default_per_page`. The default is capped
    | by this maximum too. A maximum below 1 is treated as a misconfiguration
    | (it does not disable the bound) and falls back to 50.
    |
    */
    'max_per_page' => 100,
];
