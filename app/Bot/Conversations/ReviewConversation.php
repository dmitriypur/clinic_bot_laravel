<?php

namespace App\Bot\Conversations;

use App\Models\Doctor;
use App\Models\Review;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question;

/**
 * Диалог для оставления отзывов о врачах
 * 
 * Запускается через deep link: /start review_doctor_uuid
 * Позволяет пользователям оставлять отзывы с оценкой и текстом
 * 
 * Процесс:
 * 1. Проверка существования врача по UUID
 * 2. Показ информации о враче
 * 3. Запрос оценки (1-5 звезд)
 * 4. Запрос текста отзыва (опционально)
 * 5. Подтверждение и сохранение отзыва
 * 6. Обновление рейтинга врача
 */
class ReviewConversation extends Conversation
{
    /**
     * UUID врача, для которого оставляется отзыв
     * Передается через deep link параметр
     */
    protected $doctorUuid;
    
    /**
     * Данные отзыва, собираемые в процессе диалога
     * 
     * Содержит:
     * - doctor_id: ID врача в базе данных
     * - rating: оценка от 1 до 5
     * - text: текст отзыва (опционально)
     */
    protected $reviewData = [];

    /**
     * Конструктор диалога отзыва
     * 
     * @param string $doctorUuid UUID врача из deep link параметра
     */
    public function __construct($doctorUuid)
    {
        $this->doctorUuid = $doctorUuid;
    }

    /**
     * Точка входа в диалог отзыва
     * 
     * Проверяет существование врача и запускает процесс оставления отзыва
     */
    public function run()
    {
        // Ищем врача по UUID из deep link
        $doctor = Doctor::where('uuid', $this->doctorUuid)->first();
        
        if (!$doctor) {
            $this->say('❌ Врач не найден');
            return;
        }

        // Сохраняем ID врача для последующего создания отзыва
        $this->reviewData['doctor_id'] = $doctor->id;
        $this->showDoctorAndAskRating($doctor);
    }

    /**
     * Показывает информацию о враче и запрашивает оценку
     * 
     * Отображает основную информацию о враче и предлагает
     * выбрать оценку от 1 до 5 звезд
     * 
     * @param Doctor $doctor Модель врача
     */
    public function showDoctorAndAskRating(Doctor $doctor)
    {
        $message = "⭐ Оставьте отзыв о враче:\n\n";
        $message .= "👩🏻‍⚕️ *{$doctor->full_name}*\n";
        $message .= "🎓 Стаж: {$doctor->experience} лет\n";
        $message .= "📅 Возраст приема: с {$doctor->age_admission_from} до {$doctor->age_admission_to} лет\n\n";
        $message .= "Поставьте оценку от 1 до 5:";

        $question = Question::create($message);
        
        // Создаем кнопки для каждой оценки (1-5 звезд)
        for ($i = 1; $i <= 5; $i++) {
            $stars = str_repeat('⭐', $i);
            $question->addButton(Button::create("{$stars} {$i}")->value($i));
        }
        
        $question->addButton(Button::create('❌ Отмена')->value('cancel'));

        $this->ask($question, function (Answer $answer) use ($doctor) {
            $rating = $answer->getValue();
            
            // Пользователь отменил оставление отзыва
            if ($rating === 'cancel') {
                $this->say('❌ Отзыв отменен');
                return;
            }
            
            // Проверяем что выбрана валидная оценка
            if (in_array($rating, [1, 2, 3, 4, 5])) {
                $this->reviewData['rating'] = (int)$rating;
                $this->askReviewText($doctor);
            } else {
                // Если получена неожиданная оценка - повторяем вопрос
                $this->say('❌ Выберите оценку от 1 до 5');
                $this->showDoctorAndAskRating($doctor);
            }
        });
    }

    /**
     * Запрашивает текст отзыва (опциональный)
     * 
     * Пользователь может написать подробный отзыв или пропустить этот шаг.
     * Ограничивает длину текста до 4000 символов.
     * 
     * @param Doctor $doctor Модель врача
     */
    public function askReviewText(Doctor $doctor)
    {
        $stars = str_repeat('⭐', $this->reviewData['rating']);
        
        $question = Question::create("Ваша оценка: {$stars} ({$this->reviewData['rating']}/5)\n\nНапишите текст отзыва (или нажмите 'Пропустить'):")
            ->addButtons([
                Button::create('⏭️ Пропустить')->value('skip'),
                Button::create('❌ Отмена')->value('cancel'),
            ]);

        $this->ask($question, function (Answer $answer) use ($doctor) {
            if ($answer->getValue() === 'cancel') {
                $this->say('❌ Отзыв отменен');
                return;
            }
            
            if ($answer->getValue() === 'skip') {
                // Пользователь пропустил текст отзыва
                $this->reviewData['text'] = null;
            } else {
                $text = trim($answer->getText());
                
                // Проверяем длину текста (лимит Telegram + БД)
                if (strlen($text) > 4000) {
                    $this->say('❌ Текст отзыва слишком длинный (максимум 4000 символов)');
                    $this->askReviewText($doctor);
                    return;
                }
                // Сохраняем текст, пустую строку заменяем на null
                $this->reviewData['text'] = $text ?: null;
            }
            
            $this->confirmReview($doctor);
        });
    }

    /**
     * Показывает предварительный просмотр отзыва и запрашивает подтверждение
     * 
     * Позволяет пользователю просмотреть отзыв перед отправкой,
     * отредактировать оценку или текст, либо отменить отзыв
     * 
     * @param Doctor $doctor Модель врача
     */
    public function confirmReview(Doctor $doctor)
    {
        $stars = str_repeat('⭐', $this->reviewData['rating']);
        
        $message = "📝 *Подтвердите отзыв:*\n\n";
        $message .= "👩🏻‍⚕️ Врач: {$doctor->full_name}\n";
        $message .= "⭐ Оценка: {$stars} ({$this->reviewData['rating']}/5)\n";
        
        // Показываем текст отзыва только если он есть
        if ($this->reviewData['text']) {
            $message .= "💬 Отзыв: {$this->reviewData['text']}\n";
        }

        $question = Question::create($message)
            ->addButtons([
                Button::create('✅ Отправить отзыв')->value('confirm'),
                Button::create('✏️ Изменить текст')->value('edit_text'),
                Button::create('⭐ Изменить оценку')->value('edit_rating'),
                Button::create('❌ Отмена')->value('cancel'),
            ]);

        $this->ask($question, function (Answer $answer) use ($doctor) {
            switch ($answer->getValue()) {
                case 'confirm':
                    // Сохраняем отзыв в базу данных
                    $this->saveReview($doctor);
                    break;
                case 'edit_text':
                    // Возвращаемся к редактированию текста
                    $this->askReviewText($doctor);
                    break;
                case 'edit_rating':
                    // Возвращаемся к выбору оценки
                    $this->showDoctorAndAskRating($doctor);
                    break;
                case 'cancel':
                    $this->say('❌ Отзыв отменен');
                    break;
                default:
                    // Если получен неожиданный ответ - повторяем вопрос
                    $this->confirmReview($doctor);
            }
        });
    }

    /**
     * Сохраняет отзыв в базу данных и обновляет рейтинг врача
     * 
     * Создает новую запись в таблице reviews и пересчитывает
     * общий рейтинг врача на основе всех отзывов
     * 
     * @param Doctor $doctor Модель врача
     */
    public function saveReview(Doctor $doctor)
    {
        try {
            // Получаем данные пользователя Telegram
            $user = $this->getBot()->getUser();
            
            // Создаем запись отзыва в базе данных
            $review = Review::create([
                'text' => $this->reviewData['text'],
                'rating' => $this->reviewData['rating'],
                'user_id' => $user->getId(), // Telegram user ID
                'doctor_id' => $this->reviewData['doctor_id'],
                'status' => 1, // Статус: опубликован (активен)
            ]);

            // Обновляем накопительный рейтинг врача
            // sum_ratings - сумма всех оценок
            // count_ratings - количество отзывов
            $doctor->sum_ratings += $this->reviewData['rating'];
            $doctor->count_ratings += 1;
            $doctor->save();

            // Отправляем благодарственное сообщение
            $stars = str_repeat('⭐', $this->reviewData['rating']);
            $this->say("✅ *Спасибо за отзыв!*\n\n{$stars} Ваша оценка: {$this->reviewData['rating']}/5\n\n🩺 Ваш отзыв поможет другим пациентам сделать правильный выбор.");
            
        } catch (\Exception $e) {
            // В случае ошибки уведомляем пользователя и логируем ошибку
            $this->say('❌ Произошла ошибка при сохранении отзыва. Попробуйте еще раз.');
            \Log::error('Bot review creation error: ' . $e->getMessage());
        }
    }
}
