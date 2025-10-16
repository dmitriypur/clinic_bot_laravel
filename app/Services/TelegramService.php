<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Send a plain text message to a Telegram chat.
     */
    public function sendMessage(int|string $chatId, string $message, array $options = []): void
    {
        $token = config('botman.drivers.telegram.token');

        if (empty($token)) {
            Log::warning('Telegram token is not configured, message skipped.', [
                'chat_id' => $chatId,
                'message' => $message,
            ]);

            return;
        }

        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $message,
        ], $options);

        try {
            Http::retry(2, 300)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload)
                ->throw();
        } catch (RequestException|ConnectionException $exception) {
            Log::error('Failed to send Telegram message.', [
                'chat_id' => $chatId,
                'message' => $message,
                'options' => $options,
                'exception' => $exception,
                'response_body' => $exception->response?->json(),
            ]);
        }
    }
}

