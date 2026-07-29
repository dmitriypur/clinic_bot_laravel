<?php

namespace App\Services\BookingMonitoring;

use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class BookingAvailabilityChecker
{
    public function __construct(private readonly HttpFactory $http) {}

    public function check(): BookingAvailabilityCheckResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->http
                ->connectTimeout((int) config('booking-monitoring.connect_timeout', 5))
                ->timeout((int) config('booking-monitoring.timeout', 10))
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => true,
                    ],
                ])
                ->head((string) config('booking-monitoring.url'));

            $statusCode = $response->status();
            $available = $statusCode >= 200 && $statusCode < 300;

            return new BookingAvailabilityCheckResult(
                available: $available,
                statusCode: $statusCode,
                durationMs: $this->elapsedMilliseconds($startedAt),
                error: $available ? null : "HTTP {$statusCode}",
            );
        } catch (Throwable $exception) {
            return new BookingAvailabilityCheckResult(
                available: false,
                statusCode: null,
                durationMs: $this->elapsedMilliseconds($startedAt),
                error: $exception->getMessage(),
            );
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
