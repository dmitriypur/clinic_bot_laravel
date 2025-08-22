<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class StartBotTesting extends Command
{
    protected $signature = 'bot:start-testing {--token= : Telegram bot token}';
    protected $description = 'Start bot testing with ngrok tunnel';

    public function handle()
    {
        $this->info('🤖 Запускаем тестирование Telegram бота...');
        
        // Проверяем ngrok
        if (!$this->checkNgrok()) {
            return 1;
        }

        // Получаем токен
        $token = $this->option('token') ?: $this->ask('Введите токен Telegram бота (получите у @BotFather)');
        
        if (!$token) {
            $this->error('❌ Токен не указан');
            return 1;
        }

        // Обновляем .env
        $this->updateEnvFile($token);

        $this->info("\n📋 Пошаговая инструкция:");
        $this->info('1. В новом терминале запустите: ngrok http 8000');
        $this->info('2. Скопируйте HTTPS URL из ngrok (например: https://abc123.ngrok.io)');
        
        $ngrokUrl = $this->ask('3. Введите ngrok URL (с https://)');
        
        if (!$ngrokUrl || !str_starts_with($ngrokUrl, 'https://')) {
            $this->error('❌ Неверный URL. Должен начинаться с https://');
            return 1;
        }

        $webhookUrl = rtrim($ngrokUrl, '/') . '/botman';
        
        // Устанавливаем webhook
        $this->info("\n🔗 Устанавливаем webhook: {$webhookUrl}");
        
        if ($this->setWebhook($token, $webhookUrl)) {
            $this->info('✅ Webhook установлен успешно!');
            
            // Получаем информацию о боте
            $this->getBotInfo($token);
            
            $this->info("\n🎉 Готово! Теперь можете тестировать бота:");
            $this->info('• Перейдите к боту в Telegram');
            $this->info('• Отправьте /start');
            $this->info('• Проверьте работу диалогов');
            
            $this->info("\n📊 Для отладки:");
            $this->info('• Логи Laravel: tail -f storage/logs/laravel.log');
            $this->info('• Проверка webhook: php artisan telegram:info');
            
        } else {
            $this->error('❌ Ошибка установки webhook');
            return 1;
        }

        return 0;
    }

    private function checkNgrok(): bool
    {
        $result = shell_exec('which ngrok');
        if (empty($result)) {
            $this->error('❌ ngrok не найден');
            $this->info('Установите ngrok: brew install ngrok');
            $this->info('Или скачайте с: https://ngrok.com/download');
            return false;
        }
        return true;
    }

    private function updateEnvFile($token): void
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);
        
        // Обновляем или добавляем токен
        if (str_contains($envContent, 'TELEGRAM_TOKEN=')) {
            $envContent = preg_replace('/TELEGRAM_TOKEN=.*/', "TELEGRAM_TOKEN={$token}", $envContent);
        } else {
            $envContent .= "\nTELEGRAM_TOKEN={$token}";
        }
        
        file_put_contents($envPath, $envContent);
        $this->info('✅ Токен сохранен в .env');
    }

    private function setWebhook($token, $webhookUrl): bool
    {
        $response = Http::post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => $webhookUrl,
            'max_connections' => 100,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return $data['ok'] ?? false;
        }

        return false;
    }

    private function getBotInfo($token): void
    {
        $response = Http::get("https://api.telegram.org/bot{$token}/getMe");
        
        if ($response->successful()) {
            $data = $response->json();
            if ($data['ok']) {
                $bot = $data['result'];
                $this->info("\n🤖 Информация о боте:");
                $this->info("   Имя: {$bot['first_name']}");
                if (isset($bot['username'])) {
                    $this->info("   Username: @{$bot['username']}");
                    $this->info("   Ссылка: https://t.me/{$bot['username']}");
                }
            }
        }
    }
}