<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Http\Request;

final class DataTableParams
{
    /** Bound used when `data-table.max_per_page` is set to something below 1. */
    public const FALLBACK_MAX_PER_PAGE = 50;

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
                $request->input(DataTableConfig::getPerPageKey()),
                DataTableConfig::getDefaultPerPage(),
                DataTableConfig::getMaxPerPage(),
            ),
            trashed: $request->input(DataTableConfig::getTrashedKey()),
            restoreId: $restoreRaw !== null ? (int) $restoreRaw : null,
        );
    }

    /**
     * Resolve a per-page value taken from untrusted input: anything that is not
     * a number (missing, empty, array, text) or is below 1 (zero, negative)
     * falls back to the default, anything above the maximum is cut to it. The
     * maximum is the hard bound, so the default is capped by it too. A maximum
     * below 1 is a misconfiguration and falls back to FALLBACK_MAX_PER_PAGE
     * rather than disabling the bound; the default is kept at 1 or more.
     */
    public static function clampPerPage(mixed $requested, int $default, int $max): int
    {
        $max = $max >= 1 ? $max : self::FALLBACK_MAX_PER_PAGE;
        $default = min(max(1, $default), $max);
        $requested = is_numeric($requested) ? (int) $requested : 0;

        return $requested < 1 ? $default : min($requested, $max);
    }
}
