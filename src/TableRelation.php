<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

final class TableRelation
{
    public string $relationsString;

    /** @var string[] */
    public array $relationsArray;

    /** @var ?array<int, string> */
    public ?array $columnsToSelect = null;

    public function __construct(string $relationsString)
    {
        $this->relationsString = $relationsString;
        $this->relationsArray = explode('.', $relationsString);
    }

    /** @return ?array<int, string> */
    public function getColumnsToSelect(): ?array
    {
        return $this->columnsToSelect;
    }

    /** @param ?array<int, string> $columnsToSelect */
    public function setColumnsToSelect(?array $columnsToSelect): self
    {
        $this->columnsToSelect = $columnsToSelect;

        return $this;
    }
}
