<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

class RawOrdering
{
    /**
     * The string of rawOrdering.
     *
     * @var string
     */
    public string $string;

    /**
     * Create a new rawOrdering instance.
     *
     * @param string $string
     */
    public function __construct(string $string)
    {
        $this->string = $string;
    }

    /**
     * Get the value of string.
     *
     * @return string
     */
    public function getString(): string
    {
        return $this->string;
    }
}
