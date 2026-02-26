<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Givanov95\DataTable\Columns\DateColumn;
use DateTime;
use DateTimeZone;

class DateTimeHelper
{
    /**
     * Time zone of client running application.
     *
     * @var DateTimeZone
     */
    private DateTimeZone $clientTimeZone;

    /**
     * Time zone of server.
     *
     * @var DateTimeZone
     */
    private DateTimeZone $serverTimeZone;

    /**
     * The date and time string to be processed.
     *
     * @var string
     */
    private string $dateTimeString;

    /**
     * An instance of the DateColumn class.
     *
     * @var DateColumn
     */
    public DateColumn $dateColumn;

    /**
     * The format to be used for date and time conversion.
     *
     * @var string
     */
    public string $format;

    /**
     * The converted date string.
     *
     * @var null|string
     */
    public ?string $convertedDate = null;

    /**
     * The SQL format string for the date.
     *
     * @var null|string
     */
    public ?string $sqlFormat = null;

    /**
     * Create a new DateTimeHelper instance.
     *
     * @param DateColumn   $dateColumn
     * @param DateTimeZone $clientTimeZone
     * @param DateTimeZone $serverTimeZone
     * @param string       $dateTimeString
     */
    public function __construct(DateColumn $dateColumn, DateTimeZone $clientTimeZone, DateTimeZone $serverTimeZone, string $dateTimeString)
    {
        $this->dateColumn = $dateColumn;
        $this->clientTimeZone = $clientTimeZone;
        $this->serverTimeZone = $serverTimeZone;
        $this->dateTimeString = $dateTimeString;
    }

    /**
     * Convert to appropriate format.
     *
     * @return $this
     */
    public function convert(): self
    {
        $this->format = $this->convertToDateFormat($this->dateTimeString, $this->dateColumn->dateDelimiter, $this->dateColumn->timeDelimiter);
        $this->sqlFormat = $this->toSqlFormat();

        $dateTime = DateTime::createFromFormat($this->format, $this->dateTimeString, $this->clientTimeZone);

        if (! $dateTime) {
            return $this;
        }

        $this->convertedDate = $dateTime->setTimezone($this->serverTimeZone)->format($this->format);

        return $this;
    }

    /**
     * Converts a time string to a corresponding date format string based on provided delimiters.
     *
     * @param         $timeString
     * @param         $dateDelimiter
     * @param         $timeDelimiter
     * @return string
     */
    public function convertToDateFormat($timeString, $dateDelimiter, $timeDelimiter): string
    {
        // Define patterns and corresponding PHP date format characters
        $patterns = [
            '/^\d{2}'.preg_quote($dateDelimiter).'\d{2}'.preg_quote($dateDelimiter).'\d{4}$/' => 'd'.$dateDelimiter.'m'.$dateDelimiter.'Y', // Matches dd{delimiter}mm{delimiter}YYYY format
            '/^\d{2}'.preg_quote($dateDelimiter).'\d{2}$/'                                    => 'd'.$dateDelimiter.'m', // Matches dd{delimiter}mm format
            '/^\d{2}'.preg_quote($timeDelimiter).'\d{2}$/'                                    => 'H'.$timeDelimiter.'i', // Matches HH{delimiter}MM format
            '/^\d{2}'.preg_quote($timeDelimiter).'\d{2}'.preg_quote($timeDelimiter).'\d{2}$/' => 'H'.$timeDelimiter.'i'.$timeDelimiter.'s', // Matches HH{delimiter}MM{delimiter}SS format
        ];

        // Loop through patterns to find a match
        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $timeString)) {
                return $format;
            }
        }

        // Return a default format if no match is found
        return 'H:i';
    }

    /**
     * Covert carbon date format to sql format.
     *
     * @return array|string
     */
    private function toSqlFormat(): array|string
    {
        return str_replace(['d', 'm', 'Y', 'H:i'], ['%d', '%m', '%Y', '%H:%i'], $this->format);
    }
}
