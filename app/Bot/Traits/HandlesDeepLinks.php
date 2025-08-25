<?php

namespace App\Bot\Traits;

use App\Bot\Conversations\ReviewConversation;
use BotMan\BotMan\Messages\Incoming\Answer;

/**
 * Trait для обработки deep links в любой conversation
 * 
 * Позволяет перехватывать команды /start review_* во время активной conversation
 * и автоматически переключаться на ReviewConversation
 */
trait HandlesDeepLinks
{
    /**
     * Проверяет answer на наличие deep link команд и обрабатывает их
     * 
     * Должен вызываться в начале каждого callback функции ask()
     * 
     * @param Answer $answer Ответ пользователя
     * @return bool true если был обработан deep link (нужно прекратить обработку)
     */
    protected function handleDeepLinks(Answer $answer): bool
    {
        $text = $answer->getText();
        
        // Проверяем команду /start с параметром review_
        if (preg_match('/^\/start\s+review_(.+)$/', $text, $matches)) {
            $doctorUuid = $matches[1];
            
            // Логируем перехват deep link
            \Log::info('Deep link intercepted in conversation', [
                'conversation_class' => get_class($this),
                'doctor_uuid' => $doctorUuid,
                'user_id' => $this->getBot()->getUser()->getId(),
                'original_text' => $text
            ]);
            
            // Запускаем ReviewConversation для указанного врача
            $this->getBot()->startConversation(new ReviewConversation($doctorUuid));
            
            return true; // Сигнализируем что deep link был обработан
        }
        
        return false; // Продолжаем обычную обработку
    }
}
