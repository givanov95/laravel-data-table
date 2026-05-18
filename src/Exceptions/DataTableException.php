<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Exceptions;

use Exception;
use Throwable;

class DataTableException extends Exception
{
    protected array $context = [];

    public function __construct(
        string $message,
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code'    => $this->getCode(),
            'context' => $this->context,
        ];
    }
}
