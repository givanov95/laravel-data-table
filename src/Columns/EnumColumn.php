<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

use InvalidArgumentException;

/**
 * Marks a column as backed by a PHP enum so that filters / search match by
 * case name (e.g. "Pending" → values whose enum name contains "Pending").
 */
final class EnumColumn
{
    /** @var class-string<\BackedEnum> */
    public readonly string $enumClass;

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    public function __construct(string $enumClass)
    {
        if (! enum_exists($enumClass)) {
            throw new InvalidArgumentException("{$enumClass} is not a valid enum.");
        }

        if (! is_subclass_of($enumClass, \BackedEnum::class)) {
            throw new InvalidArgumentException("{$enumClass} must be a backed enum.");
        }

        $this->enumClass = $enumClass;
    }

    /**
     * @return class-string<\BackedEnum>
     */
    public function getEnumClass(): string
    {
        return $this->enumClass;
    }
}
