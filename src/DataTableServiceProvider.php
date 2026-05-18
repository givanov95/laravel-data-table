<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Support\ServiceProvider;

class DataTableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/data-table.php',
            'data-table',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/data-table.php' => $this->app->basePath('config/data-table.php'),
            ], 'data-table-config');
        }
    }
}
