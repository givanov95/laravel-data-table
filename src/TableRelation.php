<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

class TableRelation
{
    /**
     * A string representation of the relations.
     *
     * @var string
     */
    public string $relationsString;

    /**
     * An array representation of the relations.
     *
     * @var array|string[]
     */
    public array $relationsArray;

    /**
     * Array of columns that will be selected from the relation.
     *
     * @var null|array
     */
    public ?array $columnsToSelect = null;

    /**
     * Create a new TableRelation instance.
     *
     * @param string $relationsString
     */
    public function __construct(
        string $relationsString,
    ) {
        $this->relationsString = $relationsString;

        $this->relationsArray = explode('.', $relationsString);
    }

    /**
     * Get the value of columnsToSelect.
     *
     * @return ?array
     */
    public function getColumnsToSelect(): ?array
    {
        return $this->columnsToSelect;
    }

    /**
     * Set the value of columnsToSelect.
     *
     * @param  ?array $columnsToSelect
     * @return self
     */
    public function setColumnsToSelect(?array $columnsToSelect): self
    {
        $this->columnsToSelect = $columnsToSelect;

        return $this;
    }
}
