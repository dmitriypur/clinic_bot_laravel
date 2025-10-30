<?php

namespace App\Bot\Conversations;

use App\Models\TelegramContact;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\Drivers\Telegram\Extensions\Keyboard;
use BotMan\Drivers\Telegram\Extensions\KeyboardButton;

/**
 * Диалог начального экрана Telegram-бота.
 *
 * Показывает приветствие, предлагает кнопки для передачи номера телефона
 * и запуска WebApp, где происходит дальнейшая работа пользователя.
 */
class ApplicationConversation extends Conversation
{
    public const BUTTON_SHARE_PHONE = '📱 Использовать мой номер телефона';
    public const BUTTON_SKIP_PHONE = '🔓 Открыть приложение без номера';

    /**
     * Точка входа в диалог.
     */
    public function run(): void
    {
        $this->showMainMenu();
    }

    /**
     * Показывает приветствие, сохраняет актуальный чат и отправляет кнопки.
     */
    public function showMainMenu(): void
    {
        $user = $this->bot->getUser();
        $message = $this->bot->getMessage();
        $chatId = $message->getRecipient() ?: $user->getId();

        $storedContact = TelegramContact::query()
            ->where('tg_user_id', $user->getId())
            ->first();

        if ($storedContact && (!$storedContact->tg_chat_id || (string) $storedContact->tg_chat_id !== (string) $chatId)) {
            $storedContact->tg_chat_id = $chatId;
            $storedContact->save();
        }

        $this->sendPhoneRequestKeyboard((bool) $storedContact);
        $this->sendWebAppButton($storedContact?->phone);
    }

    /**
     * Отправляет клавиатуру с запросом контакта.
     */
    protected function sendPhoneRequestKeyboard(bool $hasStoredPhone): void
    {
        $keyboardPayload = Keyboard::create()
            ->type(Keyboard::TYPE_KEYBOARD)
            ->resizeKeyboard(true)
            ->oneTimeKeyboard(false)
            ->addRow(
                KeyboardButton::create(self::BUTTON_SHARE_PHONE)->requestContact()
            )
            ->addRow(
                KeyboardButton::create(self::BUTTON_SKIP_PHONE)
            )
            ->toArray();

        $text = $hasStoredPhone
            ? 'Добро пожаловать! 🚀 При необходимости вы можете обновить номер кнопкой ниже или открыть приложение сразу.'
            : 'Добро пожаловать! 🚀 Чтобы автоматически подставить ваш телефон в заявку, нажмите кнопку ниже.';

        $this->bot->reply($text, $keyboardPayload);
    }

    /**
     * Показывает inline-кнопку запуска WebApp.
     */
    protected function sendWebAppButton(?string $phone = null): void
    {
        $url = $this->buildWebAppUrl($phone);

        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => 'Запустить приложение',
                    'web_app' => ['url' => $url],
                ],
            ]],
        ];

        $this->bot->reply('Откройте форму записи 👇', [
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Формирует URL WebApp с передачей идентификаторов пользователя и чата.
     */
    protected function buildWebAppUrl(?string $phone = null): string
    {
        $user = $this->bot->getUser();
        $message = $this->bot->getMessage();

        $baseUrl = rtrim((string) config('services.telegram.web_app_url', 'https://app.fondzrenie.ru'), '/');

        $query = [
            'tg_user_id' => $user->getId(),
            'tg_chat_id' => $message->getRecipient() ?: $user->getId(),
        ];

        $sanitizedPhone = $this->sanitizePhoneForQuery($phone);
        if ($sanitizedPhone) {
            $query['phone'] = $sanitizedPhone;
        }

        return $baseUrl . '?' . http_build_query($query);
    }

    /**
     * Удаляет из телефона лишние символы перед передачей в WebApp.
     */
    protected function sanitizePhoneForQuery(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits ?: null;
    }
}
