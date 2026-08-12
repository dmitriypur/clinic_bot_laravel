<?php

namespace Tests\Unit;

use App\Services\OneC\OneCTransportErrorClassifier;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OneCTransportErrorClassifierTest extends TestCase
{
    #[DataProvider('curlErrors')]
    public function test_it_classifies_curl_transport_errors(int $curlCode, string $expectedCategory): void
    {
        $exception = new ConnectException(
            'Sensitive transport error',
            new Request('POST', 'https://onec.example/secret'),
            null,
            ['errno' => $curlCode],
        );

        $context = app(OneCTransportErrorClassifier::class)->context($exception, 15);

        $this->assertSame($expectedCategory, $context['error_category']);
        $this->assertSame($curlCode, $context['curl_errno']);
        $this->assertSame(15, $context['timeout_seconds']);
        $this->assertArrayNotHasKey('message', $context);
        $this->assertArrayNotHasKey('url', $context);
    }

    public function test_it_uses_a_safe_network_fallback_for_unknown_errors(): void
    {
        $context = app(OneCTransportErrorClassifier::class)->context(
            new RuntimeException('Token secret and patient data'),
            15,
        );

        $this->assertSame([
            'error_category' => 'network',
            'timeout_seconds' => 15,
        ], $context);
    }

    public static function curlErrors(): array
    {
        return [
            'timeout' => [28, 'timeout'],
            'connection refused' => [7, 'connection_refused'],
            'dns' => [6, 'dns'],
            'tls handshake' => [35, 'tls'],
            'tls certificate' => [60, 'tls'],
            'empty response' => [52, 'connection_reset'],
            'connection reset' => [56, 'connection_reset'],
        ];
    }
}
