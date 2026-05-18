<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

class Column
{
    public function __construct(
        public string $databaseColumnName,
        public ?string $label = null,
        public bool $searchable = false,
        public bool $orderable = false,
        public bool $exactMatch = false,
    ) {
    }

    public function getDatabaseColumnName(): string
    {
        return $this->databaseColumnName;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isOrderable(): bool
    {
        return $this->orderable;
    }

    public function isExactMatch(): bool
    {
        return $this->exactMatch;
    }
}
