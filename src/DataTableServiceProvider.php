<?php

namespace Givanov95\DataTable;

use Illuminate\Support\ServiceProvider;

class DataTableServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

        $this->publishes([
            __DIR__.'/config/datatable.php' => $this->app->basePath('config/datatable.php'),
        ], 'config');
    }

    public function register(): void
    {

        $this->mergeConfigFrom(
            __DIR__.'/config/datatable.php',
            'datatable'
        );
    }
}
