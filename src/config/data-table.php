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
    | The DataTable reads incoming HTTP parameters using these keys. Override
    | them here if your frontend uses different conventions.
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
];
