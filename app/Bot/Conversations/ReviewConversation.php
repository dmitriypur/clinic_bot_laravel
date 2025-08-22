<?php

namespace App\Bot\Conversations;

use App\Models\Doctor;
use App\Models\Review;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question;

class ReviewConversation extends Conversation
{
    protected $doctorUuid;
    protected $reviewData = [];

    public function __construct($doctorUuid)
    {
        $this->doctorUuid = $doctorUuid;
    }

    public function run()
    {
        $doctor = Doctor::where('uuid', $this->doctorUuid)->first();
        
        if (!$doctor) {
            $this->say('❌ Врач не найден');
            return;
        }

        $this->reviewData['doctor_id'] = $doctor->id;
        $this->showDoctorAndAskRating($doctor);
    }

    public function showDoctorAndAskRating(Doctor $doctor)
    {
        $message = "⭐ Оставьте отзыв о враче:\n\n";
        $message .= "👩🏻‍⚕️ *{$doctor->full_name}*\n";
        $message .= "🎓 Стаж: {$doctor->experience} лет\n";
        $message .= "📅 Возраст приема: с {$doctor->age_admission_from} до {$doctor->age_admission_to} лет\n\n";
        $message .= "Поставьте оценку от 1 до 5:";

        $question = Question::create($message);
        
        for ($i = 1; $i <= 5; $i++) {
            $stars = str_repeat('⭐', $i);
            $question->addButton(Button::create("{$stars} {$i}")->value($i));
        }
        
        $question->addButton(Button::create('❌ Отмена')->value('cancel'));

        $this->ask($question, function (Answer $answer) use ($doctor) {
            $rating = $answer->getValue();
            
            if ($rating === 'cancel') {
                $this->say('❌ Отзыв отменен');
                return;
            }
            
            if (in_array($rating, [1, 2, 3, 4, 5])) {
                $this->reviewData['rating'] = (int)$rating;
                $this->askReviewText($doctor);
            } else {
                $this->say('❌ Выберите оценку от 1 до 5');
                $this->showDoctorAndAskRating($doctor);
            }
        });
    }

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
                $this->reviewData['text'] = null;
            } else {
                $text = trim($answer->getText());
                if (strlen($text) > 4000) {
                    $this->say('❌ Текст отзыва слишком длинный (максимум 4000 символов)');
                    $this->askReviewText($doctor);
                    return;
                }
                $this->reviewData['text'] = $text ?: null;
            }
            
            $this->confirmReview($doctor);
        });
    }

    public function confirmReview(Doctor $doctor)
    {
        $stars = str_repeat('⭐', $this->reviewData['rating']);
        
        $message = "📝 *Подтвердите отзыв:*\n\n";
        $message .= "👩🏻‍⚕️ Врач: {$doctor->full_name}\n";
        $message .= "⭐ Оценка: {$stars} ({$this->reviewData['rating']}/5)\n";
        
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
                    $this->saveReview($doctor);
                    break;
                case 'edit_text':
                    $this->askReviewText($doctor);
                    break;
                case 'edit_rating':
                    $this->showDoctorAndAskRating($doctor);
                    break;
                case 'cancel':
                    $this->say('❌ Отзыв отменен');
                    break;
                default:
                    $this->confirmReview($doctor);
            }
        });
    }

    public function saveReview(Doctor $doctor)
    {
        try {
            $user = $this->getBot()->getUser();
            
            $review = Review::create([
                'text' => $this->reviewData['text'],
                'rating' => $this->reviewData['rating'],
                'user_id' => $user->getId(),
                'doctor_id' => $this->reviewData['doctor_id'],
                'status' => 1, // Опубликован
            ]);

            // Обновляем рейтинг врача
            $doctor->sum_ratings += $this->reviewData['rating'];
            $doctor->count_ratings += 1;
            $doctor->save();

            $stars = str_repeat('⭐', $this->reviewData['rating']);
            $this->say("✅ *Спасибо за отзыв!*\n\n{$stars} Ваша оценка: {$this->reviewData['rating']}/5\n\n🩺 Ваш отзыв поможет другим пациентам сделать правильный выбор.");
            
        } catch (\Exception $e) {
            $this->say('❌ Произошла ошибка при сохранении отзыва. Попробуйте еще раз.');
            \Log::error('Bot review creation error: ' . $e->getMessage());
        }
    }
}
