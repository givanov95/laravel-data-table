<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

final class RawOrdering
{
    public function __construct(public string $string)
    {
    }

    public function getString(): string
    {
        return $this->string;
    }
}
