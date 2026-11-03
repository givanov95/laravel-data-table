<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Givanov95\DataTable\Support\SuccessRedirect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * @deprecated 4.2 DataTable::restore() and restoreRecord() restore without it.
 */
final class SoftRestorer
{
    use AuthorizesRequests;

    public function __construct(protected Model $model)
    {
    }

    public function restore(): RedirectResponse
    {
        $this->authorize('restore', [$this->model]);

        $this->model->restore();

        return SuccessRedirect::back(__('The record has been successfully restored.'));
    }
}
