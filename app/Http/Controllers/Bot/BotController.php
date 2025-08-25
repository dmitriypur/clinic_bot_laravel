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

/**
 * Основной контроллер для обработки webhook-запросов от Telegram бота
 * Отвечает за инициализацию BotMan и настройку обработчиков команд
 */
class BotController extends Controller
{
    /**
     * Главный метод обработки входящих сообщений от Telegram
     * Вызывается при каждом webhook-запросе от Telegram API
     * 
     * @param Request $request - HTTP запрос с данными сообщения от Telegram
     * @return \Illuminate\Http\Response|void
     */
    public function handle(Request $request)
    {
        try {
            // Загружаем драйвер Telegram для BotMan
            // Необходимо для работы с Telegram API
            DriverManager::loadDriver(TelegramDriver::class);

            // Получаем конфигурацию бота из config/botman.php
            $config = config('botman');
            
            // Настраиваем файловое хранилище для кеша и состояний диалогов
            // FileCache - для временного кеширования данных бота
            $fileCache = new FileCache($config['cache']['config']['path']);
            // FileStorage - для сохранения состояний диалогов пользователей
            $fileStorage = new FileStorage($config['userinfo']['config']['path']);
            
            // Создаем экземпляр BotMan с настроенными хранилищами
            // $request содержит данные webhook от Telegram
            $botman = BotManFactory::create($config, $fileCache, $request, $fileStorage);

            // Регистрируем обработчики команд и сообщений
            $this->setupBotHandlers($botman);

            // Начинаем прослушивание входящих сообщений
            // BotMan автоматически вызовет соответствующий обработчик
            $botman->listen();
        } catch (\Exception $e) {
            // Логируем любые ошибки для отладки
            \Log::error('Bot handle error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);
            
            // Возвращаем 200 OK чтобы Telegram не повторял запрос
            // Иначе Telegram будет постоянно отправлять тот же webhook
            return response('OK', 200);
        }
    }

    /**
     * Настройка обработчиков команд и сообщений бота
     * Определяет как бот будет реагировать на различные команды
     * 
     * @param BotMan $botman - экземпляр бота для регистрации обработчиков
     */
    private function setupBotHandlers(BotMan $botman)
    {
        // Обработчик команды /start - главная точка входа в бота
        $botman->hears('/start', function (BotMan $bot) {
            // Получаем полные данные сообщения от Telegram
            $payload = $bot->getMessage()->getPayload();
            $text = $payload['text'] ?? '';
            
            // Разбираем команду /start на параметры
            // Формат: /start [параметр1] [параметр2] ...
            $params = explode(' ', $text);
            
            // Проверяем есть ли параметры и начинается ли первый с 'review_'
            // Это deep link для системы отзывов: /start review_doctor_uuid
            if (count($params) > 1 && str_starts_with($params[1], 'review_')) {
                // Извлекаем UUID врача из параметра
                // Формат параметра: review_uuid_врача
                $doctorUuid = str_replace('review_', '', $params[1]);
                
                // Запускаем диалог оставления отзыва для конкретного врача
                $bot->startConversation(new ReviewConversation($doctorUuid));
                return;
            }

            // Если нет специальных параметров - запускаем обычный диалог записи
            // ApplicationConversation обрабатывает весь процесс записи к врачу
            $bot->startConversation(new ApplicationConversation());
        });

        // Fallback обработчик для всех остальных сообщений
        // Вызывается если ни один другой обработчик не сработал
        $botman->fallback(function (BotMan $bot) {
            $bot->reply('Используйте /start для начала работы с ботом.');
        });
    }
}
