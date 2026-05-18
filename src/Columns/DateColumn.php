<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

final class DateColumn
{
    public function __construct(
        public string $format,
        public string $dateDelimiter = '.',
        public string $timeDelimiter = ':',
    ) {
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getDateDelimiter(): string
    {
        return $this->dateDelimiter;
    }

    public function getTimeDelimiter(): string
    {
        return $this->timeDelimiter;
    }
}
