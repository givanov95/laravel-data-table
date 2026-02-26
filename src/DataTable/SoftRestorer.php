<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class SoftRestorer
{
    use AuthorizesRequests;

    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Restores the model.
     *
     * @return RedirectResponse
     */
    public function restore(): RedirectResponse
    {
        $this->authorize('restore', [$this->model]);

        $this->model->restore();

        return back()->with('success', __('The record has been successfully restored.'));
    }
}
