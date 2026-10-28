<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Translatable storage
    |--------------------------------------------------------------------------
    |
    | Used by TranslatableColumn. `translatable_relation` is the model's
    | morphMany relation to its translations (the table, keys and morph class
    | come from it). In the translations table, `translatable_column` holds the
    | translation key (e.g. "title", "name"), `translatable_locale_column` the
    | locale and `translatable_text_column` the text.
    |
    */
    'translatable_relation'      => 'translations',
    'translatable_column'        => 'key',
    'translatable_locale_column' => 'locale',
    'translatable_text_column'   => 'text',

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
    | (`ordering[key]`, `ordering[direction]`). `trashed` is read only by a
    | table that calls allowTrashed(). QueryBuilderTable takes the search and
    | trashed keys from anywhere in the request; when they sit under Spatie's
    | filter parameter (`filter.search`), that filter is accepted without being
    | applied by Spatie.
    |
    | `time_zone` carries the browser's zone, sent with the search, so a date
    | typed in the search box is read in the user's zone. Null puts it next to
    | `global_filter` (`filter.timeZone`).
    |
    */
    'global_filter' => 'filter.global',
    'per_page'      => 'perPage',
    'trashed'       => 'filter.trashed',
    'ordering'      => 'ordering',
    'time_zone'     => null,

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
