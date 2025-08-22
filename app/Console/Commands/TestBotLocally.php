<?php

namespace App\Console\Commands;

use App\Http\Controllers\Bot\BotController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestBotLocally extends Command
{
    protected $signature = 'bot:test-locally {--user-id=123456789} {--chat-id=123456789} {--message=/start}';
    protected $description = 'Test bot locally without webhook';

    public function handle()
    {
        $userId = $this->option('user-id');
        $chatId = $this->option('chat-id');
        $message = $this->option('message');

        $this->info("🤖 Тестируем бота локально...");
        $this->info("👤 User ID: {$userId}");
        $this->info("💬 Chat ID: {$chatId}");
        $this->info("📝 Message: {$message}");

        // Имитируем webhook payload от Telegram
        $payload = [
            'update_id' => time(),
            'message' => [
                'message_id' => time(),
                'from' => [
                    'id' => (int)$userId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'username' => 'testuser',
                    'language_code' => 'ru'
                ],
                'chat' => [
                    'id' => (int)$chatId,
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'username' => 'testuser',
                    'type' => 'private'
                ],
                'date' => time(),
                'text' => $message
            ]
        ];

        // Создаем request с payload
        $request = new Request();
        $request->merge($payload);
        $request->headers->set('Content-Type', 'application/json');

        try {
            $this->info("\n🚀 Отправляем запрос боту...");
            
            $controller = new BotController();
            $response = $controller->handle($request);
            
            $this->info("✅ Бот обработал запрос успешно!");
            $this->info("📤 Ответ отправлен пользователю");
            
        } catch (\Exception $e) {
            $this->error("❌ Ошибка: " . $e->getMessage());
            $this->error("📍 Файл: " . $e->getFile() . ':' . $e->getLine());
            
            if ($this->option('verbose')) {
                $this->error("📋 Stack trace:");
                $this->error($e->getTraceAsString());
            }
        }

        $this->newLine();
        $this->info("💡 Другие примеры:");
        $this->info("php artisan bot:test-locally --message='/start'");
        $this->info("php artisan bot:test-locally --message='/start review_uuid-врача'");
        $this->info("php artisan bot:test-locally --message='Записаться на прием'");
        
        return 0;
    }
}