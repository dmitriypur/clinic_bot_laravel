<?php

namespace App\Services\OneC;

use App\Models\IntegrationEndpoint;
use App\Services\OneC\Exceptions\OneCApiException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OneCApiClient
{
    public function __construct(private readonly HttpFactory $http) {}

    protected function decodeResponse(Response $response): array
    {
        $data = $response->json();

        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode($response->body(), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new OneCApiException('Некорректный формат ответа 1С.', [
            'response' => $response->body(),
        ]);
    }

    /**
     * Создаёт бронь в 1С.
     *
     * @throws OneCApiException
     */
    public function bookSlot(IntegrationEndpoint $endpoint, array $payload): array
    {
        // Сохраняем обратную совместимость со старыми филиалами,
        // где в UI были только manual_booking_* поля.
        $path = Arr::get($endpoint->credentials, 'booking_path')
            ?? Arr::get($endpoint->credentials, 'manual_booking_path')
            ?? 'events?action=bookslot';

        $options = [
            'json' => $payload,
        ];

        // 1С часто ждёт custom Authorization, поэтому читаем токен прямо из credentials.
        $authorization = Arr::get($endpoint->credentials, 'booking_authorization')
            ?? Arr::get($endpoint->credentials, 'booking_token')
            ?? Arr::get($endpoint->credentials, 'manual_booking_authorization')
            ?? Arr::get($endpoint->credentials, 'manual_booking_token');

        if ($authorization) {
            $options['headers']['Authorization'] = $authorization;
        }

        $response = $this->request($endpoint, 'POST', $path, $options);

        $data = $response->json();

        if (! is_array($data)) {
            throw new OneCApiException('Некорректный формат ответа 1С при бронировании слота.', [
                'endpoint_id' => $endpoint->id,
                'response' => $response->body(),
            ]);
        }

        if (! array_key_exists('status_code', $data)) {
            $data['status_code'] = $response->status();
        }

        return $data;
    }

    /**
     * Отменяет бронь в 1С.
     *
     * @throws OneCApiException
     */
    public function cancelBooking(IntegrationEndpoint $endpoint, string $externalAppointmentId, array $payload = []): array
    {
        $path = Arr::get($endpoint->credentials, 'cancel_booking_path')
            ?? 'events?action=cancelrecord';

        $options = [
            'json' => array_merge($payload, [
                'claim_id' => $externalAppointmentId,
            ]),
        ];

        // Для отмены можно использовать любой доступный токен; идём по цепочке fallback'ов.
        $authorization = Arr::get($endpoint->credentials, 'cancel_booking_authorization')
            ?? Arr::get($endpoint->credentials, 'cancel_booking_token')
            ?? Arr::get($endpoint->credentials, 'manual_booking_authorization')
            ?? Arr::get($endpoint->credentials, 'manual_booking_token')
            ?? Arr::get($endpoint->credentials, 'booking_authorization')
            ?? Arr::get($endpoint->credentials, 'booking_token');

        if ($authorization) {
            $options['headers']['Authorization'] = $authorization;
        }

        $response = $this->request($endpoint, 'POST', $path, $options);

        $data = $this->decodeResponse($response);

        if (! array_key_exists('status_code', $data)) {
            $data['status_code'] = $response->status();
        }

        return $data;
    }

    /**
     * Создаёт запись напрямую (без выбора слота) в 1С.
     *
     * @throws OneCApiException
     */
    public function createManualBooking(IntegrationEndpoint $endpoint, array $payload): array
    {
        $path = Arr::get($endpoint->credentials, 'manual_booking_path', 'events?action=newrecord');

        $options = [
            'json' => $payload,
        ];

        $authorization = Arr::get($endpoint->credentials, 'manual_booking_authorization')
            ?? Arr::get($endpoint->credentials, 'manual_booking_token');

        if ($authorization) {
            $options['headers']['Authorization'] = $authorization;
        }

        $response = $this->request($endpoint, 'POST', $path, $options);

        $data = $response->json();

        if (! is_array($data)) {
            $data = $this->decodeResponse($response);
        }

        if (! array_key_exists('status_code', $data)) {
            $data['status_code'] = $response->status();
        }

        return $data;
    }

    /**
     * Универсальный запрос к 1С.
     *
     * @throws OneCApiException
     */
    protected function request(IntegrationEndpoint $endpoint, string $method, string $uri, array $options = []): Response
    {
        $pending = $this->prepareRequest($endpoint);

        try {
            // Используем Http\Client\Factory, чтобы единообразно логировать и ловить ошибки сети.
            $response = $pending->send($method, $this->buildUri($endpoint, $uri), $options);
        } catch (\Throwable $exception) {
            Log::error('Ошибка сети при обращении к 1С.', [
                'endpoint_id' => $endpoint->id,
                'method' => $method,
                'uri' => $uri,
                'error' => $exception->getMessage(),
            ]);

            throw new OneCApiException('Не удалось связаться с 1С.', [
                'endpoint_id' => $endpoint->id,
                'method' => $method,
                'uri' => $uri,
            ], 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('1С вернула ошибку.', [
                'endpoint_id' => $endpoint->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new OneCApiException('1С вернула ошибку.', [
                'endpoint_id' => $endpoint->id,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);
        }

        return $response;
    }

    protected function prepareRequest(IntegrationEndpoint $endpoint): PendingRequest
    {
        $credentials = $endpoint->credentials ?? [];

        $timeout = (int) ($credentials['timeout'] ?? config('services.onec.timeout', 15));

        $request = $this->http
            ->timeout($timeout)
            ->acceptJson();

        $authType = strtolower((string) ($endpoint->auth_type ?? ''));

        if ($authType === 'basic') {
            $request = $request->withBasicAuth(
                $credentials['username'] ?? '',
                $credentials['password'] ?? ''
            );
        } elseif (in_array($authType, ['bearer', 'token'], true)) {
            $request = $request->withToken((string) ($credentials['token'] ?? ''));
        }

        // Некоторые контуры 1С используют самоподписанные сертификаты — в этом случае можно отключить проверку.
        if (array_key_exists('verify_ssl', $credentials) && $credentials['verify_ssl'] === false) {
            $request = $request->withoutVerifying();
        }

        $hasExplicitAuthorizationHeader = ! empty($credentials['manual_booking_authorization'])
            || ! empty($credentials['manual_booking_token'])
            || ! empty($credentials['booking_authorization'])
            || ! empty($credentials['booking_token']);

        if (! empty($credentials['lo_token']) && ! $hasExplicitAuthorizationHeader) {
            $request = $request->withHeaders([
                'X-LO-Token' => $credentials['lo_token'],
            ]);
        }

        return $request;
    }

    protected function buildUri(IntegrationEndpoint $endpoint, string $uri): string
    {
        $baseUrl = $endpoint->base_url ?? Arr::get($endpoint->credentials, 'base_url');

        if (! $baseUrl) {
            // Если базовый URL не задан, отправляем относительный путь — полезно в тестах.
            return ltrim($uri, '/');
        }

        $baseUrl = Str::finish($baseUrl, '/');

        return $baseUrl.ltrim($uri, '/');
    }
}
