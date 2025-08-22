<?php

namespace App\Http\Controllers\Bot;

use App\Bot\Conversations\ApplicationConversation;
use App\Bot\Conversations\ReviewConversation;
use App\Http\Controllers\Controller;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Cache\FileCache;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Storages\Drivers\FileStorage;
use BotMan\Drivers\Telegram\TelegramDriver;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function handle(Request $request)
    {
        // Load the driver
        DriverManager::loadDriver(TelegramDriver::class);

        // Create BotMan instance
        $config = config('botman');
        
        // Setup File storage for conversations
        $fileCache = new FileCache($config['cache']['config']['path']);
        $fileStorage = new FileStorage($config['userinfo']['config']['path']);

        $botman = BotManFactory::create($config, $fileCache, $request, $fileStorage);

        // Define bot commands
        $this->setupBotHandlers($botman);

        // Listen for messages
        $botman->listen();
    }

    private function setupBotHandlers(BotMan $botman)
    {
        // Start command
        $botman->hears('/start', function (BotMan $bot) {
            $payload = $bot->getMessage()->getPayload();
            $text = $payload['text'] ?? '';
            
            // Извлекаем параметры из /start команды
            $params = explode(' ', $text);
            
            if (count($params) > 1 && str_starts_with($params[1], 'review_')) {
                // Начинаем диалог отзывов
                $doctorUuid = str_replace('review_', '', $params[1]);
                $bot->startConversation(new ReviewConversation($doctorUuid));
                return;
            }

            // Обычное меню
            $bot->startConversation(new ApplicationConversation());
        });

        // Fallback for any other message
        $botman->fallback(function (BotMan $bot) {
            $bot->reply('Используйте /start для начала работы с ботом.');
        });
    }
}
