<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Thin wrapper around Laravel's LengthAwarePaginator that exposes the
 * properties the frontend table consumes directly.
 *
 * @mixin LengthAwarePaginator
 */
class Paginator
{
    private LengthAwarePaginator $lengthAwarePaginator;

    public int $itemsLength;

    public int $perPage;

    /** @var array<int|string, string> */
    public array $links;

    public int $currentPage;

    public int $lastPage;

    public string $lastPageUrl;

    public int $pagesRange = 2;

    public function __construct(LengthAwarePaginator $lengthAwarePaginator)
    {
        $this->lengthAwarePaginator = $lengthAwarePaginator;
        $this->init();
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->lengthAwarePaginator->{$method}(...$parameters);
    }

    private function init(): void
    {
        $this->currentPage = $this->lengthAwarePaginator->currentPage();
        $this->lastPage = $this->lengthAwarePaginator->lastPage();
        $this->lastPageUrl = $this->lengthAwarePaginator->url($this->lastPage);
        $this->perPage = $this->lengthAwarePaginator->perPage();
        $this->itemsLength = $this->lengthAwarePaginator->total();

        $prevPageLinks = max($this->currentPage - $this->pagesRange, 1);
        $nextPageLinks = min($this->currentPage + $this->pagesRange, $this->lastPage);
        $this->links = $this->lengthAwarePaginator->getUrlRange($prevPageLinks, $nextPageLinks);
    }

    public function getPagesRange(): int
    {
        return $this->pagesRange;
    }

    public function setPagesRange(int $range): self
    {
        $this->pagesRange = $range;
        $this->init();

        return $this;
    }
}
