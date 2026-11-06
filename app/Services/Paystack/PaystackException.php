<?php

namespace App\Services\Paystack;

use RuntimeException;
use Throwable;

class PaystackException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * HTTP status returned by Paystack, or 0 when the request never completed.
     */
    public function status(): int
    {
        return $this->status;
    }
}
