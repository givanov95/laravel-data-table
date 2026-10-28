<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * The parts of a column filter (`filter[status][]=paid`, `filter[created_at][from]=…`)
 * that DataTable and QueryBuilderTable share: reading the untrusted request
 * value, the options of a select, and the clauses.
 *
 * @internal
 */
final class FilterClauses
{
    /** At most this many values of one select are read. */
    public const MAX_VALUES = 100;

    /**
     * The column filters of the request, by column key: a text (`string`), the
     * values of a select (`list<string>`) or a date range (`from` / `to`).
     * Anything else (a nested array, an object) is dropped, so no value can
     * fail a typed comparison or reach SQL as anything but a binding.
     *
     * @return array<string, string|list<string>|array{from?: string, to?: string}>
     */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $filters = [];

        foreach ($input as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value)) {
                $filters[$key] = (string) $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                $values = array_values(array_map('strval', array_filter($value, 'is_scalar')));
                $filters[$key] = array_slice(array_values(array_unique($values)), 0, self::MAX_VALUES);

                continue;
            }

            $range = array_map('strval', array_filter(
                array_intersect_key($value, ['from' => true, 'to' => true]),
                'is_scalar',
            ));

            if ($range !== []) {
                $filters[$key] = $range;
            }
        }

        return $filters;
    }

    /**
     * The values of a select, as a list: one value is a list of one.
     *
     * @param string|list<string>|array<string, string> $value
     * @return list<string>
     */
    public static function values(string|array $value): array
    {
        $values = is_string($value) ? [$value] : (array_is_list($value) ? $value : []);

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    /**
     * The options a select offers, as `[{value, label}]`. Takes a list of
     * `['value' => …, 'label' => …]`, a `value => label` map, a plain list of
     * values (their own labels) or a closure that returns any of these (it
     * runs once, when the table is built). A map keyed 0, 1, 2… reads as a
     * plain list: give such ids as `['value' => …, 'label' => …]`.
     *
     * @param array<array-key, mixed>|Closure(): array<array-key, mixed> $options
     * @return list<array{value: string|int, label: string}>
     */
    public static function options(array|Closure $options): array
    {
        $options = $options instanceof Closure ? (array) $options() : $options;
        // A plain list of values (`['paid', 'open']`) is its own labels, not 0, 1, 2.
        $plain = array_is_list($options) && array_filter($options, 'is_scalar') === $options;
        $list = [];

        foreach ($options as $key => $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $list[] = ['value' => self::optionValue($option['value']), 'label' => (string) ($option['label'] ?? $option['value'])];

                continue;
            }

            if (is_scalar($option)) {
                $list[] = ['value' => self::optionValue($plain ? $option : $key), 'label' => (string) $option];
            }
        }

        return $list;
    }

    /**
     * The cases of an enum as select options: the case's value, labelled by
     * its `label()` method when it has one, else by its name.
     *
     * @param class-string<BackedEnum> $enumClass
     * @return list<array{value: string|int, label: string}>
     */
    public static function enumOptions(string $enumClass): array
    {
        return array_map(static fn (BackedEnum $case): array => [
            'value' => $case->value,
            'label' => method_exists($case, 'label') ? (string) $case->label() : $case->name,
        ], $enumClass::cases());
    }

    /**
     * The requested values that are options, as the options have them (an int
     * stays an int), so nothing but an offered value is ever compared.
     *
     * @param list<string> $requested
     * @param list<array{value: string|int, label: string}> $options
     * @return list<string|int>
     */
    public static function offered(array $requested, array $options): array
    {
        $byText = [];

        foreach ($options as $option) {
            $byText[(string) $option['value']] = $option['value'];
        }

        return array_values(array_intersect_key($byText, array_flip($requested)));
    }

    /**
     * `column IN (…)`; no values match nothing (an empty IN is a syntax error).
     *
     * @param list<string|int> $values
     */
    public static function whereIn(Builder $query, string $column, array $values): void
    {
        $values === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $values);
    }

    /**
     * The valid ends of a date range: ISO dates (`2026-10-28`, as a date input
     * sends them) that exist. One date alone is the range of that day.
     *
     * @param string|list<string>|array<string, string> $value
     * @return array{from?: string, to?: string}
     */
    public static function dateRange(string|array $value): array
    {
        $range = is_string($value) ? ['from' => $value, 'to' => $value] : $value;

        return array_filter([
            'from' => self::isoDate($range['from'] ?? null),
            'to' => self::isoDate($range['to'] ?? null),
        ], static fn (?string $date): bool => $date !== null);
    }

    /**
     * `from <= column < the day after to`, on the stored value (any driver,
     * and an index can be used). For a column that holds a time, the days are
     * the user's: midnight in `$zone`, moved to the server's zone.
     *
     * @param array{from?: string, to?: string} $range
     */
    public static function whereDateRange(Builder $query, string $column, array $range, bool $withTime, ?DateTimeZone $zone = null): void
    {
        $server = new DateTimeZone(date_default_timezone_get());
        $zone = $withTime ? ($zone ?? $server) : $server;

        $bound = static function (string $date, bool $nextDay) use ($zone, $server, $withTime): string {
            $day = (new DateTimeImmutable($date, $zone))->setTime(0, 0);
            $day = $nextDay ? $day->modify('+1 day') : $day;

            return $withTime ? $day->setTimezone($server)->format('Y-m-d H:i:s') : $day->format('Y-m-d');
        };

        if (isset($range['from'])) {
            $query->where($column, '>=', $bound($range['from'], false));
        }

        if (isset($range['to'])) {
            $query->where($column, '<', $bound($range['to'], true));
        }
    }

    private static function isoDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        // From year 1000: a date input passes through 0002, 0020… while a year is typed, and
        // year 1 moved to the server's zone can fall into year 0, which PostgreSQL rejects.
        return (int) $m[1] >= 1000 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    private static function optionValue(mixed $value): string|int
    {
        return is_int($value) ? $value : (string) $value;
    }
}
