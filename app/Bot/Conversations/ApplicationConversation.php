<?php

namespace App\Bot\Conversations;

use App\Bot\Traits\HandlesDeepLinks;
use App\Models\Application;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question;

/**
 * Основной диалог для записи на прием к врачу
 * 
 * Этот класс обрабатывает весь процесс записи пациента к врачу:
 * - Показывает главное меню с опциями
 * - Собирает данные о пациенте пошагово
 * - Позволяет выбрать город, клинику, врача
 * - Создает заявку в базе данных
 * 
 * Поддерживаемые сценарии:
 * - Обычная запись на прием
 * - Запись с промокодом 
 * - Просмотр врачей без записи
 */
class ApplicationConversation extends Conversation
{
    use HandlesDeepLinks;
    /**
     * Массив для хранения данных заявки во время диалога
     * 
     * Содержит поля:
     * - scenario: тип сценария (appointment, appointment_promo, view_doctors)
     * - city_id, city_name: выбранный город
     * - clinic_id, clinic_name: выбранная клиника (опционально)
     * - doctor_id: выбранный врач
     * - full_name: ФИО пациента
     * - phone: телефон для связи
     * - birth_date: дата рождения (опционально)
     * - promo_code: промокод (если используется)
     * - full_name_parent: ФИО родителя (для детей)
     */
    protected $applicationData = [];

    /**
     * Точка входа в диалог
     * Автоматически вызывается при старте разговора
     */
    public function run()
    {
        $this->showMainMenu();
    }

    /**
     * Показывает главное меню бота с основными опциями
     * 
     * Предлагает пользователю выбрать одно из действий:
     * - Записаться на прием (обычная запись)
     * - Просмотр врачей (без записи)
     * - Запись с промокодом (со скидкой)
     * - Переход в Telegram канал
     */
    public function showMainMenu()
    {
        $question = Question::create('🏥 Добро пожаловать в медицинский центр! Выберите действие:')
            ->addButtons([
                Button::create('📝 Записаться на прием')->value('make_appointment'),
                Button::create('👩🏻‍⚕️ Просмотр врачей')->value('view_doctors'),
                Button::create('🎁 Запись с промокодом')->value('appointment_promo'),
                Button::create('👉 Телеграм канал')->url('https://t.me/kidsvision1'),
            ]);

        $this->ask($question, function (Answer $answer) {
            // КРИТИЧНО: Проверяем deep links в первую очередь
            if ($this->handleDeepLinks($answer)) {
                return; // Deep link обработан, прекращаем выполнение
            }
            
            $value = $answer->getValue();
            
            // Определяем сценарий работы в зависимости от выбора пользователя
            switch ($value) {
                case 'make_appointment':
                    // Обычная запись - сначала запрашиваем дату рождения
                    $this->applicationData['scenario'] = 'appointment';
                    $this->askBirthDate();
                    break;
                case 'view_doctors':
                    // Просмотр врачей - сразу переходим к выбору города
                    $this->applicationData['scenario'] = 'view_doctors';
                    $this->askCity();
                    break;
                case 'appointment_promo':
                    // Запись с промокодом - сначала запрашиваем промокод
                    $this->applicationData['scenario'] = 'appointment_promo';
                    $this->askPromoCode();
                    break;
                default:
                    // Если получен неожиданный ответ - показываем меню заново
                    $this->showMainMenu();
            }
        });
    }

    /**
     * Запрашивает дату рождения пациента (опциональное поле)
     * 
     * Проверяет формат даты на соответствие dd.mm.yyyy
     * Пользователь может пропустить этот шаг
     */
    public function askBirthDate()
    {
        $question = Question::create('📅 Введите дату рождения пациента в формате dd.mm.yyyy (например: 10.10.2000)')
            ->addButtons([
                Button::create('Пропустить')->value('skip'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            $text = $answer->getText();
            
            // Если пользователь пропускает - переходим к выбору города
            if ($answer->getValue() === 'skip') {
                $this->askCity();
                return;
            }
            
            // Возврат в главное меню
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }

            // Проверяем формат даты регулярным выражением
            // Формат: 2 цифры.2 цифры.4 цифры (dd.mm.yyyy)
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $text)) {
                $this->applicationData['birth_date'] = $text;
                $this->askCity();
            } else {
                // Если формат неверный - повторяем запрос
                $this->say('❌ Неверный формат даты. Используйте формат dd.mm.yyyy');
                $this->askBirthDate();
            }
        });
    }

    /**
     * Запрашивает промокод для записи со скидкой
     * 
     * Валидация промокода не выполняется в боте,
     * проверка происходит при обработке заявки в 1C
     */
    public function askPromoCode()
    {
        $question = Question::create('🎁 Введите промокод:')
            ->addButtons([
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }

            // Сохраняем промокод как есть, без валидации
            $promoCode = $answer->getText();
            $this->applicationData['promo_code'] = $promoCode;
            $this->askCity();
        });
    }

    /**
     * Показывает список доступных городов для выбора
     * 
     * Загружает активные города из базы данных (status = 1)
     * Ограничивается 10 городами для соблюдения лимитов Telegram
     */
    public function askCity()
    {
        // Получаем только активные города, отсортированные по названию
        $cities = City::where('status', 1)->orderBy('name')->get();
        
        if ($cities->isEmpty()) {
            $this->say('❌ Города не найдены');
            return;
        }

        $question = Question::create('🏙️ Выберите город:');
        
        // Добавляем города как кнопки (максимум 10 для соблюдения лимитов Telegram API)
        foreach ($cities->take(10) as $city) {
            $question->addButton(Button::create($city->name)->value('city_' . $city->id));
        }
        
        $question->addButton(Button::create('В меню')->value('menu'));

        $this->ask($question, function (Answer $answer) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }

            // Проверяем что выбран город (значение начинается с 'city_')
            if (str_starts_with($answer->getValue(), 'city_')) {
                $cityId = str_replace('city_', '', $answer->getValue());
                $city = City::find($cityId);
                
                if ($city) {
                    // Сохраняем выбранный город
                    $this->applicationData['city_id'] = $cityId;
                    $this->applicationData['city_name'] = $city->name;
                    
                    // Выбираем следующий шаг в зависимости от сценария
                    if ($this->applicationData['scenario'] === 'view_doctors') {
                        // Для просмотра врачей - сразу показываем список
                        $this->showDoctors();
                    } else {
                        // Для записи - предлагаем выбрать клинику или врача
                        $this->askClinicOrDoctor();
                    }
                } else {
                    $this->say('❌ Город не найден');
                    $this->askCity();
                }
            } else {
                // Если получен неожиданный ответ - повторяем вопрос
                $this->askCity();
            }
        });
    }

    public function askClinicOrDoctor()
    {
        $question = Question::create('👀 Узнайте, как сохранить детям зрение.
💯 Эффективные методы лечения и рекомендации ведущих детских офтальмологов России в нашем телеграм канале 
"Национального Фонда защиты детского зрения"')
            ->addButtons([
                Button::create('👩🏻‍⚕️ Смотреть врачей')->value('doctors'),
                Button::create('🏥 Смотреть клиники')->value('clinics'),
                Button::create('Назад')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            switch ($answer->getValue()) {
                case 'doctors':
                    $this->showDoctors();
                    break;
                case 'clinics':
                    $this->showClinics();
                    break;
                case 'back':
                    $this->askCity();
                    break;
                case 'menu':
                    $this->showMainMenu();
                    break;
                default:
                    $this->askClinicOrDoctor();
            }
        });
    }

    public function showClinics()
    {
        $cityId = $this->applicationData['city_id'];
        $clinics = Clinic::whereHas('cities', function ($query) use ($cityId) {
            $query->where('city_id', $cityId);
        })->where('status', 1)->get();

        if ($clinics->isEmpty()) {
            $this->say('❌ В выбранном городе нет доступных клиник');
            $this->askCity();
            return;
        }

        $question = Question::create('🏥 Выберите клинику:');
        
        foreach ($clinics->take(10) as $clinic) {
            $question->addButton(Button::create($clinic->name)->value('clinic_' . $clinic->id));
        }
        
        $question->addButton(Button::create('Назад')->value('back'));
        $question->addButton(Button::create('В меню')->value('menu'));

        $this->ask($question, function (Answer $answer) use ($clinics) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            if ($answer->getValue() === 'back') {
                $this->askClinicOrDoctor();
                return;
            }

            if (str_starts_with($answer->getValue(), 'clinic_')) {
                $clinicId = str_replace('clinic_', '', $answer->getValue());
                $clinic = $clinics->find($clinicId);
                
                if ($clinic) {
                    $this->applicationData['clinic_id'] = $clinicId;
                    $this->applicationData['clinic_name'] = $clinic->name;
                    $this->showDoctorsInClinic($clinicId);
                } else {
                    $this->say('❌ Клиника не найдена');
                    $this->showClinics();
                }
            } else {
                $this->showClinics();
            }
        });
    }

    public function showDoctors($clinicId = null)
    {
        $cityId = $this->applicationData['city_id'];
        
        $query = Doctor::whereHas('clinics.cities', function ($q) use ($cityId) {
            $q->where('city_id', $cityId);
        })->where('status', 1);
        
        if ($clinicId) {
            $query->whereHas('clinics', function ($q) use ($clinicId) {
                $q->where('clinic_id', $clinicId);
            });
        }
        
        $doctors = $query->get();

        if ($doctors->isEmpty()) {
            $this->say('❌ Врачи не найдены');
            return;
        }

        $question = Question::create('👩🏻‍⚕️ Выберите врача:');
        
        foreach ($doctors->take(10) as $doctor) {
            $name = $doctor->full_name;
            $question->addButton(Button::create($name)->value('doctor_' . $doctor->id));
        }
        
        $question->addButton(Button::create('Назад')->value('back'));
        $question->addButton(Button::create('В меню')->value('menu'));

        $this->ask($question, function (Answer $answer) use ($doctors) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            if ($answer->getValue() === 'back') {
                $this->askClinicOrDoctor();
                return;
            }

            if (str_starts_with($answer->getValue(), 'doctor_')) {
                $doctorId = str_replace('doctor_', '', $answer->getValue());
                $doctor = $doctors->find($doctorId);
                
                if ($doctor) {
                    $this->applicationData['doctor_id'] = $doctorId;
                    $this->showDoctorInfo($doctor);
                } else {
                    $this->say('❌ Врач не найден');
                    $this->showDoctors();
                }
            } else {
                $this->showDoctors();
            }
        });
    }

    public function showDoctorsInClinic($clinicId)
    {
        $this->showDoctors($clinicId);
    }

    public function showDoctorInfo(Doctor $doctor)
    {
        $info = "👩🏻‍⚕️ *Врач*: {$doctor->full_name}\n";
        $info .= "📆 *Возраст*: {$doctor->age}\n";
        $info .= "🎓 *Стаж (лет)*: {$doctor->experience}\n";
        $info .= "⭐ *Рейтинг*: {$doctor->rating}\n";
        $info .= "📅 *Возраст приема*: с {$doctor->age_admission_from} до {$doctor->age_admission_to} лет\n\n";
        
        $clinics = $doctor->clinics;
        if ($clinics->isNotEmpty()) {
            $info .= "🏥 *Клиники приема*:\n";
            foreach ($clinics as $clinic) {
                $info .= "• {$clinic->name}\n";
            }
        }

        $question = Question::create($info)
            ->addButtons([
                Button::create('📅 Записаться')->value('make_appointment'),
                Button::create('✍️ Оставить отзыв')->value('leave_review'),
                Button::create('⭐ Отзывы')->value('view_reviews'),
                Button::create('Назад')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) use ($doctor) {
            // КРИТИЧНО: Проверяем deep links в первую очередь
            if ($this->handleDeepLinks($answer)) {
                return; // Deep link обработан, прекращаем выполнение
            }
            
            switch ($answer->getValue()) {
                case 'make_appointment':
                    $this->askPhone();
                    break;
                case 'leave_review':
                    $reviewUrl = "https://t.me/" . config('botman.drivers.telegram.username') . "?start=review_{$doctor->uuid}";
                    $this->say("✍️ *Оставить отзыв о враче*\n\nДля оставления отзыва перейдите по ссылке:\n{$reviewUrl}\n\nИли поделитесь этой ссылкой с теми, кто хочет оставить отзыв о враче {$doctor->full_name}");
                    $this->showDoctorInfo($doctor);
                    break;
                case 'view_reviews':
                    $this->showReviews($doctor);
                    break;
                case 'back':
                    $this->showDoctors();
                    break;
                case 'menu':
                    $this->showMainMenu();
                    break;
                default:
                    $this->showDoctorInfo($doctor);
            }
        });
    }

    public function askPhone()
    {
        $question = Question::create('📱 Введите номер телефона:')
            ->addButtons([
                Button::create('Назад')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            if ($answer->getValue() === 'back') {
                $doctor = Doctor::find($this->applicationData['doctor_id']);
                $this->showDoctorInfo($doctor);
                return;
            }

            $phone = $answer->getText();
            
            // Простая проверка телефона
            if (preg_match('/^\+?[0-9]{10,15}$/', str_replace([' ', '-', '(', ')'], '', $phone))) {
                $this->applicationData['phone'] = $phone;
                $this->askFullName();
            } else {
                $this->say('❌ Неверный формат номера телефона');
                $this->askPhone();
            }
        });
    }

    public function askFullName()
    {
        $question = Question::create('🪪 Введите ФИО пациента:')
            ->addButtons([
                Button::create('Назад')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            if ($answer->getValue() === 'back') {
                $this->askPhone();
                return;
            }

            $fullName = trim($answer->getText());
            
            if (strlen($fullName) >= 3) {
                $this->applicationData['full_name'] = $fullName;
                $this->askParentName();
            } else {
                $this->say('❌ ФИО должно содержать минимум 3 символа');
                $this->askFullName();
            }
        });
    }

    public function askParentName()
    {
        $question = Question::create('👨 Введите ФИО родителя (если записываете ребенка):')
            ->addButtons([
                Button::create('Пропустить')->value('skip'),
                Button::create('Назад')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            if ($answer->getValue() === 'back') {
                $this->askFullName();
                return;
            }
            
            if ($answer->getValue() === 'skip') {
                $this->askConsent();
                return;
            }

            $parentName = trim($answer->getText());
            $this->applicationData['full_name_parent'] = $parentName;
            $this->askConsent();
        });
    }

    public function askConsent()
    {
        $question = Question::create('✍️ Подтвердите согласие на обработку персональных данных:')
            ->addButtons([
                Button::create('✅ Даю согласие')->value('consent'),
                Button::create('Назад')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            if ($answer->getValue() === 'back') {
                $this->askParentName();
                return;
            }
            
            if ($answer->getValue() === 'consent') {
                $this->createApplication();
            } else {
                $this->askConsent();
            }
        });
    }

    public function createApplication()
    {
        try {
            $user = $this->getBot()->getUser();
            
            // Генерируем ID как в оригинале
            $applicationId = now()->format('YmdHis') . rand(1000, 9999);
            
            $applicationData = [
                'id' => $applicationId,
                'city_id' => $this->applicationData['city_id'],
                'clinic_id' => $this->applicationData['clinic_id'] ?? null,
                'doctor_id' => $this->applicationData['doctor_id'] ?? null,
                'full_name' => $this->applicationData['full_name'],
                'full_name_parent' => $this->applicationData['full_name_parent'] ?? null,
                'birth_date' => $this->applicationData['birth_date'] ?? null,
                'phone' => $this->applicationData['phone'],
                'promo_code' => $this->applicationData['promo_code'] ?? null,
                'tg_user_id' => $user->getId(),
                'tg_chat_id' => $this->getBot()->getMessage()->getRecipient(),
                'send_to_1c' => false,
            ];

            $application = Application::create($applicationData);

            $this->say("✅ *Заявка успешно создана!*\n\n📋 Номер заявки: `{$application->id}`\n\n🏥 Наш менеджер свяжется с вами в ближайшее время для подтверждения записи.");
            
            // TODO: Отправка в 1C через очередь
            // TODO: Уведомления через webhook
            
        } catch (\Exception $e) {
            $this->say('❌ Произошла ошибка при создании заявки. Попробуйте еще раз.');
            \Log::error('Bot application creation error: ' . $e->getMessage());
        }
    }

    public function showReviews(Doctor $doctor)
    {
        $reviews = $doctor->reviews()->where('status', 1)->latest()->take(5)->get();
        
        if ($reviews->isEmpty()) {
            $this->say('📝 Отзывов пока нет');
            $this->showDoctorInfo($doctor);
            return;
        }

        $message = "⭐ *Отзывы о враче {$doctor->full_name}*:\n\n";
        
        foreach ($reviews as $review) {
            $stars = str_repeat('⭐', $review->rating);
            $message .= "{$stars} ({$review->rating}/5)\n";
            if ($review->text) {
                $message .= "📝 {$review->text}\n";
            }
            $message .= "📅 {$review->created_at->format('d.m.Y')}\n\n";
        }

        $question = Question::create($message)
            ->addButtons([
                Button::create('Назад к врачу')->value('back'),
                Button::create('В меню')->value('menu'),
            ]);

        $this->ask($question, function (Answer $answer) use ($doctor) {
            if ($answer->getValue() === 'menu') {
                $this->showMainMenu();
                return;
            }
            
            $this->showDoctorInfo($doctor);
        });
    }
}
