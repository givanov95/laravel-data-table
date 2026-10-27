<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Illuminate\Support\ServiceProvider;

/**
 * The shadcn-vue UI is published into the app (`--tag=data-table-shadcn`),
 * and its texts go through the npm package's translator.
 */
final class ShadcnStubsTest extends TestCase
{
    private const STUBS = __DIR__.'/../stubs/shadcn/components/ui/data-table';

    public function testTheTagPublishesTheWholeDataTableFolder(): void
    {
        $paths = ServiceProvider::pathsToPublish(null, 'data-table-shadcn');

        $this->assertCount(1, $paths);
        $this->assertSame(realpath(self::STUBS), realpath((string) array_key_first($paths)));
        $this->assertSame(resource_path('js/components/ui/data-table'), array_values($paths)[0]);

        foreach (['ServerDataTable.vue', 'DataTable.vue', 'DataTableColumnHeader.vue', 'features.ts', 'index.ts'] as $file) {
            $this->assertFileExists(self::STUBS.'/'.$file);
        }
    }

    public function testTheStubsDoNotDependOnTheAppsTranslatorOrAnOldComposableCopy(): void
    {
        foreach (glob(self::STUBS.'/*') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('@/plugins/translation', $source, basename($file));
            $this->assertStringNotContainsString('@/composables/useLaravelDataTable', $source, basename($file));
        }
    }
}
