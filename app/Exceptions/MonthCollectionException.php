<?php

namespace App\Exceptions;

use RuntimeException;

class MonthCollectionException extends RuntimeException
{
    /**
     * @param string $message
     * @param string $errorCode
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'UNRESOLVED_MONTH_OWNERSHIP',
        private readonly array $context = []
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
