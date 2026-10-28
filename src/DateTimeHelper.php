<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Givanov95\DataTable\Columns\DateColumn;

final class DateTimeHelper
{
    public string $format = '';

    public ?string $convertedDate = null;

    public function __construct(
        public DateColumn $dateColumn,
        private DateTimeZone $clientTimeZone,
        private DateTimeZone $serverTimeZone,
        private string $dateTimeString,
    ) {
    }

    /**
     * A time zone taken from untrusted input, or null when it is not one PHP
     * knows (`new DateTimeZone('xx')` throws). Aliases a browser may send
     * (`Europe/Kiev`, `Etc/UTC`, `+02:00`) are accepted.
     */
    public static function zone(mixed $name): ?DateTimeZone
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (\Exception|\ValueError) {
            return null;
        }
    }

    public function convert(): self
    {
        $this->format = $this->convertToDateFormat(
            $this->dateTimeString,
            $this->dateColumn->dateDelimiter,
            $this->dateColumn->timeDelimiter,
        );

        // Only a time is moved between zones. A date alone (`d.m.Y`, `d.m`) is
        // compared as typed: converting it would take the current time of day
        // and could land on the day before or after, depending on when the
        // search is made. A whole date on a column with a time is a range().
        $zone = str_contains($this->format, 'H') ? $this->clientTimeZone : $this->serverTimeZone;

        $dateTime = DateTime::createFromFormat($this->format, $this->dateTimeString, $zone);

        if (! $dateTime) {
            return $this;
        }

        $this->convertedDate = $dateTime->setTimezone($this->serverTimeZone)->format($this->format);

        return $this;
    }

    /**
     * For a column that holds a time (its display format has `H`): a whole
     * date (`d.m.Y`) or a date and time (`d.m.Y H:i`, `d.m.Y H:i:s`) as the
     * span it covers in the client's zone, `[from, to)`, in the server's zone,
     * as `Y-m-d H:i:s`. So a row at 23:30 UTC is found by the date it has in
     * Sofia (the next day). Null for anything else, or a date that does not
     * exist (`31.02.2026`).
     *
     * @return array{0: string, 1: string}|null
     */
    public function range(): ?array
    {
        if (! str_contains($this->dateColumn->format, 'H')) {
            return null;
        }

        $dd = $this->dateColumn->dateDelimiter;
        $td = $this->dateColumn->timeDelimiter;
        $format = $this->convertToDateFormat($this->dateTimeString, $dd, $td);

        $step = match ($format) {
            "d{$dd}m{$dd}Y" => '+1 day',
            "d{$dd}m{$dd}Y H{$td}i" => '+1 minute',
            "d{$dd}m{$dd}Y H{$td}i{$td}s" => '+1 second',
            default => null,
        };

        if ($step === null) {
            return null;
        }

        // `!`: midnight, not the current time, for the parts that are not given.
        $from = DateTimeImmutable::createFromFormat('!'.$format, $this->dateTimeString, $this->clientTimeZone);

        if (! $from || $from->format($format) !== $this->dateTimeString) {
            return null;
        }

        // The step is taken in the client's zone, so a day with a DST change is 23 or 25 hours.
        $to = $from->modify($step);

        return [
            $from->setTimezone($this->serverTimeZone)->format('Y-m-d H:i:s'),
            $to->setTimezone($this->serverTimeZone)->format('Y-m-d H:i:s'),
        ];
    }

    public function convertToDateFormat(string $timeString, string $dateDelimiter, string $timeDelimiter): string
    {
        $patterns = [
            '/^\d{2}'.preg_quote($dateDelimiter).'\d{2}'.preg_quote($dateDelimiter).'\d{4} \d{2}'.preg_quote($timeDelimiter).'\d{2}$/' => 'd'.$dateDelimiter.'m'.$dateDelimiter.'Y H'.$timeDelimiter.'i',
            '/^\d{2}'.preg_quote($dateDelimiter).'\d{2}'.preg_quote($dateDelimiter).'\d{4} \d{2}'.preg_quote($timeDelimiter).'\d{2}'.preg_quote($timeDelimiter).'\d{2}$/' => 'd'.$dateDelimiter.'m'.$dateDelimiter.'Y H'.$timeDelimiter.'i'.$timeDelimiter.'s',
            '/^\d{2}'.preg_quote($dateDelimiter).'\d{2}'.preg_quote($dateDelimiter).'\d{4}$/' => 'd'.$dateDelimiter.'m'.$dateDelimiter.'Y',
            '/^\d{2}'.preg_quote($dateDelimiter).'\d{2}$/'                                    => 'd'.$dateDelimiter.'m',
            '/^\d{2}'.preg_quote($timeDelimiter).'\d{2}$/'                                    => 'H'.$timeDelimiter.'i',
            '/^\d{2}'.preg_quote($timeDelimiter).'\d{2}'.preg_quote($timeDelimiter).'\d{2}$/' => 'H'.$timeDelimiter.'i'.$timeDelimiter.'s',
        ];

        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $timeString)) {
                return $format;
            }
        }

        return 'H:i';
    }
}
