<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

use BackedEnum;

class EnumColumn
{
    /**
     * Create a new EnumColumn instance.
     *
     * @param Enum $enum
     */
    public function __construct(
        public readonly BackedEnum $enum
    ) {
    }

    /**
     * Get the value of enum
     */
    public function getEnum()
    {
        return $this->enum;
    }
}
