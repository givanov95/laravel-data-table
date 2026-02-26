<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Http\Request;

class DataTableParams
{
    public function __construct(
        public ?string $globalFilter = null,
        public int $perPage = 10,
        public ?string $trashed = null,
        public ?int $restoreId = null,
    ) {
    }

    /**
     * Create params from Laravel Request.
     * This keeps HTTP logic OUT of DataTable.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            globalFilter: $request->input('filter.global'),
            perPage: (int) $request->input('perPage', 10),
            trashed: $request->input('filter.trashed'),
            restoreId: (int) $request->input('restore_id'),
        );
    }
}
