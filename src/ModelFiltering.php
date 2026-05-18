<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Database\Eloquent\Builder;

final class ModelFiltering
{
    private bool $showTrashed = false;

    private bool $onlyTrashed = false;

    public function showTrashed(bool $show = true): self
    {
        $this->showTrashed = $show;

        return $this;
    }

    public function onlyTrashed(bool $only = true): self
    {
        $this->onlyTrashed = $only;

        return $this;
    }

    public function apply(Builder $query): Builder
    {
        if ($this->onlyTrashed) {
            return $query->onlyTrashed();
        }

        if ($this->showTrashed) {
            return $query->withTrashed();
        }

        return $query;
    }
}
