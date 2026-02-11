<?php

namespace App\Services\OneC\Exceptions;

use RuntimeException;

class OneCException extends RuntimeException
{
    public function __construct(string $message = '', private readonly array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Дополнительные данные об ошибке, полезные для логирования.
     */
    public function context(): array
    {
        return $this->context;
    }
}
