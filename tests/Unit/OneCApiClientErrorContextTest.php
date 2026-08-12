<?php

namespace Tests\Unit;

use App\Models\IntegrationEndpoint;
use App\Services\OneC\Exceptions\OneCApiException;
use App\Services\OneC\OneCApiClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OneCApiClientErrorContextTest extends TestCase
{
    public function test_it_exposes_only_safe_transport_context_to_the_booking_layer(): void
    {
        Http::fake(function (): never {
            throw new ConnectException(
                'Sensitive URL, token and patient data',
                new Request('POST', 'https://onec.example/secret'),
                null,
                ['errno' => 28],
            );
        });

        $endpoint = new IntegrationEndpoint([
            'base_url' => 'https://onec.example',
            'credentials' => [
                'timeout' => 12,
                'manual_booking_path' => 'events?action=newrecord',
            ],
        ]);

        try {
            app(OneCApiClient::class)->createManualBooking($endpoint, [
                'patient_name' => 'Секретные данные пациента',
            ]);
            $this->fail('Expected the 1C request to fail.');
        } catch (OneCApiException $exception) {
            $this->assertSame([
                'endpoint_id' => null,
                'error_category' => 'timeout',
                'timeout_seconds' => 12,
                'curl_errno' => 28,
            ], $exception->context());
            $this->assertStringNotContainsString('onec.example', $exception->getMessage());
            $this->assertStringNotContainsString('Секретные данные пациента', $exception->getMessage());
        }
    }
}
