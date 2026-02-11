<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook 
                          {url? : Webhook URL} 
                          {--delete : Delete the current webhook}';

    protected $description = 'Set or delete Telegram webhook';

    public function handle()
    {
        $token = config('botman.telegram.token');

        if (! $token) {
            $this->error('TELEGRAM_TOKEN не найден в конфигурации');
            $this->info('Добавьте TELEGRAM_TOKEN=your_bot_token в .env файл');

            return 1;
        }

        if ($this->option('delete')) {
            return $this->deleteWebhook($token);
        }

        $url = $this->argument('url') ?: config('botman.telegram.webhook_url');

        if (! $url) {
            $this->error('URL webhook не указан');
            $this->info('Использование:');
            $this->info('  php artisan telegram:webhook https://your-domain.com/botman');
            $this->info('  или добавьте TELEGRAM_WEBHOOK_URL в .env');

            return 1;
        }

        return $this->setWebhook($token, $url);
    }

    private function setWebhook($token, $url)
    {
        $this->info("Настройка webhook: {$url}");

        $response = Http::post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => $url,
            'max_connections' => 100,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if ($data['ok']) {
                $this->info('✅ Webhook успешно установлен!');

                return 0;
            } else {
                $this->error('❌ Ошибка: '.$data['description']);

                return 1;
            }
        } else {
            $this->error('❌ Ошибка HTTP: '.$response->status());

            return 1;
        }
    }

    private function deleteWebhook($token)
    {
        $this->info('Удаление webhook...');

        $response = Http::post("https://api.telegram.org/bot{$token}/deleteWebhook");

        if ($response->successful()) {
            $data = $response->json();
            if ($data['ok']) {
                $this->info('✅ Webhook удален!');

                return 0;
            } else {
                $this->error('❌ Ошибка: '.$data['description']);

                return 1;
            }
        } else {
            $this->error('❌ Ошибка HTTP: '.$response->status());

            return 1;
        }
    }
}
