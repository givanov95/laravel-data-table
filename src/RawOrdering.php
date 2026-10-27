<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

final class RawOrdering
{
    /**
     * @param ?Ordering $reportedAs what the expression amounts to for `toArray()`'s
     *                              `state.sort` (e.g. `created_at DESC, id DESC` is
     *                              `created_at` DESC); null reports no sort, since an
     *                              arbitrary expression is not one column
     */
    public function __construct(public string $string, public ?Ordering $reportedAs = null)
    {
    }

    public function getString(): string
    {
        return $this->string;
    }
}
