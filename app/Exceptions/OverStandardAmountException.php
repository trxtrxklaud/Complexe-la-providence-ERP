<?php

namespace App\Exceptions;

use InvalidArgumentException;

class OverStandardAmountException extends InvalidArgumentException
{
    /**
     * @param string $message
     * @param float $enteredAmount
     * @param float $fullRate
     * @param float $suggestedAmount
     */
    public function __construct(
        string $message,
        public readonly float $enteredAmount = 0.0,
        public readonly float $fullRate = 0.0,
        public readonly float $suggestedAmount = 0.0,
    ) {
        parent::__construct($message);
    }
}
