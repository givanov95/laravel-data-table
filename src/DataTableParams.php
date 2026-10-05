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
            perPage: self::clampPerPage(
                (int) $request->input(DataTableConfig::getPerPageKey()),
                DataTableConfig::getDefaultPerPage(),
                DataTableConfig::getMaxPerPage(),
            ),
            trashed: $request->input(DataTableConfig::getTrashedKey()),
            restoreId: $restoreRaw !== null ? (int) $restoreRaw : null,
        );
    }

    /**
     * Resolve a per-page value taken from untrusted input: anything below 1
     * (missing, zero, negative, non-numeric) falls back to the default, anything
     * above the maximum is cut to it. The maximum is the hard bound, so the
     * default is capped by it too; both are kept at 1 or more even if the
     * config is wrong.
     */
    public static function clampPerPage(int $requested, int $default, int $max): int
    {
        $max = max(1, $max);
        $default = min(max(1, $default), $max);

        return $requested < 1 ? $default : min($requested, $max);
    }
}
