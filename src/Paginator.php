<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Pagination\LengthAwarePaginator;

class Paginator
{
    /**
     * The retrieved paginator instance.
     *
     * @var LengthAwarePaginator
     */
    private LengthAwarePaginator $lengthAwarePaginator;

    /**
     * The retrieved items' length.
     *
     * @var int
     */
    public int $itemsLength;

    /**
     * The items per page.
     *
     * @var int
     */
    public int $perPage;

    /**
     * The retrieved pagination links.
     *
     * @var array
     */
    public array $links;

    /**
     * The current page number.
     *
     * @var int
     */
    public int $currentPage;

    /**
     * The current last page number.
     *
     * @var int
     */
    public int $lastPage;

    /**
     * The current last page url.
     *
     * @var string
     */
    public string $lastPageUrl;

    /**
     * The range of links to display around the current page.
     *
     * @var int
     */
    public int $pagesRange = 2;

    /**
     * Create a new PaginationService instance.
     *
     * @param LengthAwarePaginator $lengthAwarePaginator
     */
    public function __construct(LengthAwarePaginator $lengthAwarePaginator)
    {
        $this->lengthAwarePaginator = $lengthAwarePaginator;
        $this->init();
    }

    /**
     * Forward calls to the wrapped paginator instance.
     *
     * @param  string $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->lengthAwarePaginator->{$method}(...$parameters);
    }

    /**
     * Initialize the PaginationService.
     *
     * @return void
     */
    private function init(): void
    {
        $this->currentPage = $this->currentPage();
        $this->lastPage = $this->lastPage();
        $this->lastPageUrl = $this->url($this->lastPage);
        $this->perPage = $this->perPage();
        $this->itemsLength = $this->total();
        $prevPageLinks = max($this->currentPage - $this->pagesRange, 1);
        $nextPageLinks = min($this->currentPage + $this->pagesRange, $this->lastPage());
        $this->links = $this->getUrlRange($prevPageLinks, $nextPageLinks);
    }

    /**
     * Get the pagination links range.
     *
     * @return int
     */
    public function getPagesRange(): int
    {
        return $this->pagesRange;
    }

    /**
     * Set the pagination links range.
     *
     * @param  int  $range
     * @return self
     */
    public function setPagesRange(int $range): self
    {
        $this->pagesRange = $range;

        return $this;
    }
}
