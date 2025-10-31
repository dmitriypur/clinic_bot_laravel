<?php

namespace App\Http\Controllers\Bot;

use App\Bot\Conversations\ApplicationConversation;
use App\Http\Controllers\Controller;
use App\Models\TelegramContact;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Cache\FileCache;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Storages\Drivers\FileStorage;
use BotMan\Drivers\Telegram\TelegramDriver;
use BotMan\BotMan\Messages\Attachments\Contact;
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


        // Обработчик команды /start для запуска WebApp-диалога
        $botman->hears('/start.*', function (BotMan $bot) {
            $bot->startConversation(new ApplicationConversation());
        });

        $botman->receivesContact(function (BotMan $bot, Contact $contact) {
            $this->handleSharedContact($bot, $contact);
        });

        $botman->hears(ApplicationConversation::BUTTON_SKIP_PHONE, function (BotMan $bot) {
            $this->handleSkipPhoneSharing($bot);
        });

        // Fallback обработчик для всех остальных сообщений
        // Вызывается если ни один другой обработчик не сработал
        $botman->fallback(function (BotMan $bot) {
            $bot->reply('Используйте /start для начала работы с ботом.');
        });
    }

    private function handleSharedContact(BotMan $bot, Contact $contact): void
    {
        $user = $bot->getUser();
        $userId = $user?->getId();

        if (!$userId) {
            return;
        }

        $message = $bot->getMessage();
        $chatId = $message->getRecipient() ?: $userId;

        $normalizedPhone = $this->normalizePhone($contact->getPhoneNumber());

        if ($normalizedPhone) {
            TelegramContact::updateOrCreate(
                ['tg_user_id' => $userId],
                [
                    'tg_chat_id' => $chatId,
                    'phone' => $normalizedPhone,
                ]
            );
        }

        $bot->reply('Спасибо! Мы сохранили ваш номер и подставим его в заявку.', [
            'reply_markup' => json_encode(['remove_keyboard' => true], JSON_UNESCAPED_UNICODE),
        ]);

        $this->sendWebAppButton($bot, $normalizedPhone);
    }

    private function handleSkipPhoneSharing(BotMan $bot): void
    {
        $user = $bot->getUser();
        $userId = $user?->getId();

        $storedPhone = null;

        if ($userId) {
            $storedPhone = TelegramContact::query()
                ->where('tg_user_id', $userId)
                ->value('phone');
        }

        $bot->reply('Хорошо, вы сможете ввести телефон вручную в приложении.', [
            'reply_markup' => json_encode(['remove_keyboard' => true], JSON_UNESCAPED_UNICODE),
        ]);

        $this->sendWebAppButton($bot, $storedPhone);
    }

    private function sendWebAppButton(BotMan $bot, ?string $phone = null): void
    {
        $url = $this->buildWebAppUrl($bot, $phone);

        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => 'Запустить приложение',
                    'web_app' => ['url' => $url],
                ],
            ]],
        ];

        $bot->reply('Откройте форму записи 👇', [
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function buildWebAppUrl(BotMan $bot, ?string $phone = null): string
    {
        $user = $bot->getUser();
        $message = $bot->getMessage();

        $baseUrl = rtrim((string) config('services.telegram.web_app_url', 'https://adminzrenie.ru/app'), '/');

        $query = [
            'tg_user_id' => $user?->getId(),
            'tg_chat_id' => $message->getRecipient() ?: $user?->getId(),
        ];

        $sanitizedPhone = $this->sanitizePhoneForQuery($phone);
        if ($sanitizedPhone) {
            $query['phone'] = $sanitizedPhone;
        }

        return $baseUrl . '?' . http_build_query($query);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }

        return '+' . $digits;
    }

    private function sanitizePhoneForQuery(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits ?: null;
    }
}
