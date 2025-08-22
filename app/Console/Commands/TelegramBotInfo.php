<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramBotInfo extends Command
{
    protected $signature = 'telegram:info';
    protected $description = 'Get Telegram bot information and webhook status';

    public function handle()
    {
        $token = config('botman.telegram.token');
        
        if (!$token || $token === 'your_bot_token_here') {
            $this->error('❌ TELEGRAM_TOKEN не настроен');
            $this->info('Добавьте TELEGRAM_TOKEN=your_bot_token в .env файл');
            return 1;
        }

        $this->info('🤖 Получение информации о боте...');
        
        // Получаем информацию о боте
        $botInfo = $this->getBotInfo($token);
        if ($botInfo) {
            $this->displayBotInfo($botInfo);
        }

        // Получаем информацию о webhook
        $webhookInfo = $this->getWebhookInfo($token);
        if ($webhookInfo) {
            $this->displayWebhookInfo($webhookInfo);
        }

        return 0;
    }

    private function getBotInfo($token)
    {
        $response = Http::get("https://api.telegram.org/bot{$token}/getMe");
        
        if ($response->successful()) {
            $data = $response->json();
            if ($data['ok']) {
                return $data['result'];
            }
        }
        
        $this->error('❌ Не удалось получить информацию о боте');
        return null;
    }

    private function getWebhookInfo($token)
    {
        $response = Http::get("https://api.telegram.org/bot{$token}/getWebhookInfo");
        
        if ($response->successful()) {
            $data = $response->json();
            if ($data['ok']) {
                return $data['result'];
            }
        }
        
        $this->error('❌ Не удалось получить информацию о webhook');
        return null;
    }

    private function displayBotInfo($botInfo)
    {
        $this->info("\n📋 Информация о боте:");
        $this->table(['Параметр', 'Значение'], [
            ['ID', $botInfo['id']],
            ['Имя', $botInfo['first_name']],
            ['Username', '@' . ($botInfo['username'] ?? 'не указан')],
            ['Может присоединяться к группам', $botInfo['can_join_groups'] ? 'Да' : 'Нет'],
            ['Может читать все сообщения', $botInfo['can_read_all_group_messages'] ? 'Да' : 'Нет'],
            ['Поддерживает inline режим', $botInfo['supports_inline_queries'] ? 'Да' : 'Нет'],
        ]);

        if (isset($botInfo['username'])) {
            $this->info("🔗 Ссылка на бота: https://t.me/{$botInfo['username']}");
        }
    }

    private function displayWebhookInfo($webhookInfo)
    {
        $this->info("\n🔗 Информация о webhook:");
        
        if (empty($webhookInfo['url'])) {
            $this->warn('⚠️  Webhook не установлен');
            $this->info('Используйте: php artisan telegram:webhook <URL>');
        } else {
            $this->table(['Параметр', 'Значение'], [
                ['URL', $webhookInfo['url']],
                ['Статус', $webhookInfo['has_custom_certificate'] ? 'С сертификатом' : 'Обычный'],
                ['Количество подключений', $webhookInfo['max_connections'] ?? 'не указано'],
                ['Разрешенные обновления', implode(', ', $webhookInfo['allowed_updates'] ?? ['все'])],
                ['Последняя ошибка', $webhookInfo['last_error_message'] ?? 'нет'],
                ['Дата последней ошибки', isset($webhookInfo['last_error_date']) ? date('Y-m-d H:i:s', $webhookInfo['last_error_date']) : 'нет'],
            ]);

            if (!empty($webhookInfo['last_error_message'])) {
                $this->error("❌ Последняя ошибка: {$webhookInfo['last_error_message']}");
            } else {
                $this->info('✅ Webhook работает без ошибок');
            }
        }
    }
}