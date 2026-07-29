<?php

namespace Tests\Unit;

use App\Services\BookingMonitoring\BookingAvailabilityChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingAvailabilityCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking-monitoring.url' => 'http://85.93.54.201:5490/unf_telephony',
            'booking-monitoring.connect_timeout' => 5,
            'booking-monitoring.timeout' => 10,
        ]);
    }

    public function test_it_reports_available_only_for_a_successful_head_response(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $result = app(BookingAvailabilityChecker::class)->check();

        $this->assertTrue($result->available);
        $this->assertSame(200, $result->statusCode);
        $this->assertNull($result->error);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'HEAD'
                && $request->url() === 'http://85.93.54.201:5490/unf_telephony';
        });
    }

    public function test_it_reports_an_http_error_as_unavailable(): void
    {
        Http::fake([
            '*' => Http::response('', 503),
        ]);

        $result = app(BookingAvailabilityChecker::class)->check();

        $this->assertFalse($result->available);
        $this->assertSame(503, $result->statusCode);
        $this->assertSame('HTTP 503', $result->error);
    }

    public function test_it_reports_a_connection_error_as_unavailable_without_throwing(): void
    {
        Http::fake([
            '*' => Http::failedConnection('Connection refused'),
        ]);

        $result = app(BookingAvailabilityChecker::class)->check();

        $this->assertFalse($result->available);
        $this->assertNull($result->statusCode);
        $this->assertSame('Connection refused', $result->error);
    }
}
