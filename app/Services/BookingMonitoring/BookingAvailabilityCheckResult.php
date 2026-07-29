<?php

namespace App\Services\BookingMonitoring;

final readonly class BookingAvailabilityCheckResult
{
    public function __construct(
        public bool $available,
        public ?int $statusCode,
        public int $durationMs,
        public ?string $error = null,
    ) {}
}
