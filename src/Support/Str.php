<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

class Str
{
    public static function camelCaseToSnakeCase(string $input): string
    {
        $snakeCase = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);

        return strtolower($snakeCase);
    }
}
