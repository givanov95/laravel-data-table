<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use DateTime;
use DateTimeZone;
use Givanov95\DataTable\Columns\DateColumn;

final class DateTimeHelper
{
    public string $format = '';

    public ?string $convertedDate = null;

    public ?string $sqlFormat = null;

    public function __construct(
        public DateColumn $dateColumn,
        private DateTimeZone $clientTimeZone,
        private DateTimeZone $serverTimeZone,
        private string $dateTimeString,
    ) {
    }

    public function convert(): self
    {
        $this->format = $this->convertToDateFormat(
            $this->dateTimeString,
            $this->dateColumn->dateDelimiter,
            $this->dateColumn->timeDelimiter,
        );
        $this->sqlFormat = $this->toSqlFormat();

        $dateTime = DateTime::createFromFormat($this->format, $this->dateTimeString, $this->clientTimeZone);

        if (! $dateTime) {
            return $this;
        }

        $this->convertedDate = $dateTime->setTimezone($this->serverTimeZone)->format($this->format);

        return $this;
    }

    public function convertToDateFormat(string $timeString, string $dateDelimiter, string $timeDelimiter): string
    {
        $patterns = [
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

    private function toSqlFormat(): string
    {
        return str_replace(['d', 'm', 'Y', 'H:i'], ['%d', '%m', '%Y', '%H:%i'], $this->format);
    }
}
