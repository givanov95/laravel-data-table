<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

use Givanov95\DataTable\Support\Str;
use InvalidArgumentException;

final class RelationColumn extends Column
{
    /** @var string[] */
    public readonly array $relations;

    public readonly string $relationPath;

    public readonly string $relationString;

    public readonly string $relationColumn;

    public readonly string $targetTable;

    public function __construct(
        string $relationString,
        ?string $label = null,
        bool $searchable = false,
        bool $orderable = false,
        bool $exactMatch = false,
    ) {
        $parts = explode('.', $relationString);

        if (count($parts) < 2) {
            throw new InvalidArgumentException(
                "RelationColumn requires a dot-notated string with at least one relation and one column (e.g. 'user.name')."
            );
        }

        $this->relations = $parts;
        $this->relationPath = $relationString;

        $columnName = array_pop($parts);
        $this->relationColumn = $columnName;
        $this->relationString = implode('.', $parts);
        $this->targetTable = Str::camelCaseToSnakeCase(end($parts));

        parent::__construct(
            databaseColumnName: $columnName,
            label: $label,
            searchable: $searchable,
            orderable: $orderable,
            exactMatch: $exactMatch,
        );
    }
}
