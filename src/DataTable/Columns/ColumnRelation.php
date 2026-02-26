<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

use Givanov95\DataTable\Support\Str;
use InvalidArgumentException;

class ColumnRelation
{
    public array $relationsArray;

    public string $relationString;

    public array $relationWithColumn;

    public string $relationColumn;

    public array $relation;

    public string $relationTable;

    /**
     * Create a new ColumnRelation instance.
     *
     * @param array $relationsArray
     */
    public function __construct(
        array $relationsArray,
    ) {
        $this->relationsArray = $relationsArray;

        $this->initProps();
    }

    /**
     * Initialize properties.
     *
     * @return void
     */
    private function initProps(): void
    {
        if (count($this->relationsArray) < 2) {
            throw new InvalidArgumentException('Relation argument invalid. Too few relations');
        }

        $this->relationColumn = end($this->relationsArray);
        $this->relationWithColumn = $this->relationsArray;
        array_pop($this->relationsArray);
        $this->relation = $this->relationsArray;
        $this->relationString = implode('.', $this->relation);
        $tableElement = array_slice($this->relation, -2);
        $this->relationTable = Str::camelCaseToSnakeCase(end($tableElement));
    }
}
