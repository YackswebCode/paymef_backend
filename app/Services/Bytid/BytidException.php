<?php

namespace App\Services\Bytid;

use RuntimeException;

class BytidException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        private readonly string $errorCode = 'provider_error'
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
