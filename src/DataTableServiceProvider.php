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

            // The shadcn-vue UI for the TanStack composable of the npm package
            // (`@givanov95/vue-data-table/tanstack`). It imports the app's own
            // `@/components/ui/*`, so it is copied into the app; re-publish with
            // --force to update it.
            $this->publishes([
                __DIR__.'/../stubs/shadcn/components/ui/data-table' => $this->app->resourcePath('js/components/ui/data-table'),
            ], 'data-table-shadcn');
        }
    }
}
