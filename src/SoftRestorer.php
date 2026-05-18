<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

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

        return back()->with('success', __('The record has been successfully restored.'));
    }
}
