<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

class Str
{
    /**
     * Convert camel case words to snake case.
     *
     * @param  string $input
     * @return string
     */
    public static function camelCaseToSnakeCase(string $input): string
    {
        $snakeCase = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);

        return strtolower($snakeCase);
    }
}
