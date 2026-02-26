<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

class DateColumn
{
    public string $format;

    public string $dateDelimiter;

    public string $timeDelimiter;

    /**
     * Create a new DateColumn instance.
     *
     * @param string $format
     * @param        $dateDelimiter
     * @param        $timeDelimiter
     */
    public function __construct(string $format, $dateDelimiter, $timeDelimiter)
    {
        $this->format = $format;
        $this->dateDelimiter = $dateDelimiter;
        $this->timeDelimiter = $timeDelimiter;
    }

    /**
     * Get the value of dateDelimiter.
     *
     * @return string
     */
    public function getDateDelimiter(): string
    {
        return $this->dateDelimiter;
    }

    /**
     * Set the value of dateDelimiter.
     *
     * @param  string $dateDelimiter
     * @return self
     */
    public function setDateDelimiter(string $dateDelimiter): self
    {
        $this->dateDelimiter = $dateDelimiter;

        return $this;
    }

    /**
     * Get the value of timeDelimiter.
     *
     * @return string
     */
    public function getTimeDelimiter(): string
    {
        return $this->timeDelimiter;
    }

    /**
     * Set the value of timeDelimiter.
     *
     * @param  string $timeDelimiter
     * @return self
     */
    public function setTimeDelimiter(string $timeDelimiter): self
    {
        $this->timeDelimiter = $timeDelimiter;

        return $this;
    }
}
