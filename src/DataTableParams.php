<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Http\Request;

final class DataTableParams
{
    public function __construct(
        public ?string $globalFilter = null,
        public int $perPage = 15,
        public ?string $trashed = null,
        public ?int $restoreId = null,
    ) {
    }

    /**
     * Build params from a Laravel Request, looking up the keys defined in
     * config/data-table.php. Keeps all HTTP knowledge out of the DataTable
     * class.
     */
    public static function fromRequest(Request $request): self
    {
        $restoreRaw = $request->input(DataTableConfig::getRestoreIdKey());

        return new self(
            globalFilter: $request->input(DataTableConfig::getGlobalFilterKey()),
            perPage: (int) $request->input(
                DataTableConfig::getPerPageKey(),
                DataTableConfig::getDefaultPerPage(),
            ),
            trashed: $request->input(DataTableConfig::getTrashedKey()),
            restoreId: $restoreRaw !== null ? (int) $restoreRaw : null,
        );
    }
}
