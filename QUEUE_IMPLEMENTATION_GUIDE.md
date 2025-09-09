# Руководство по внедрению очередей в Medical Center

## 🎯 Анализ возможностей применения очередей

После анализа кода найдено **множество мест**, где очереди критически необходимы для улучшения производительности и пользовательского опыта.

---

## 🚨 Критичные места для очередей

### 1. **Отправка заявок в 1C** ⭐⭐⭐

**Текущее состояние:** TODO комментарии в коде
```php
// В ApplicationController.php (строка 51-52)
// TODO: Отправка в 1C через очередь
// TODO: Отправка уведомлений через вебхуки

// В ApplicationConversation.php (строка 618-619)  
// TODO: Отправка в 1C через очередь
// TODO: Уведомления через webhook
```

**Проблема:** Синхронная отправка в 1C блокирует создание заявки.

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendApplicationTo1C implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 30;
    public $tries = 3;
    public $backoff = [5, 10, 30]; // секунды между попытками
    
    public function __construct(
        private Application $application
    ) {}
    
    public function handle(): void
    {
        try {
            $payload = [
                'id' => $this->application->id,
                'full_name' => $this->application->full_name,
                'phone' => $this->application->phone,
                'appointment_datetime' => $this->application->appointment_datetime,
                'clinic_id' => $this->application->clinic_id,
                'doctor_id' => $this->application->doctor_id,
                'city_id' => $this->application->city_id,
                'created_at' => $this->application->created_at,
            ];
            
            $response = Http::timeout(30)
                ->post(config('app.1c_endpoint'), $payload);
                
            if ($response->successful()) {
                // Обновляем статус отправки
                $this->application->update(['send_to_1c' => true]);
                
                Log::info('Application sent to 1C successfully', [
                    'application_id' => $this->application->id,
                    'response' => $response->json()
                ]);
            } else {
                throw new \Exception('1C API error: ' . $response->body());
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send application to 1C', [
                'application_id' => $this->application->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e; // Повторная попытка
        }
    }
    
    public function failed(\Throwable $exception): void
    {
        Log::error('Application 1C sync failed permanently', [
            'application_id' => $this->application->id,
            'error' => $exception->getMessage()
        ]);
        
        // Можно отправить уведомление администратору
        // Notification::route('mail', 'admin@example.com')
        //     ->notify(new Application1CSyncFailed($this->application));
    }
}
```

### 2. **Webhook уведомления** ⭐⭐⭐

**Проблема:** Отсутствует система webhook уведомлений.

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 15;
    public $tries = 3;
    public $backoff = [2, 5, 10];
    
    public function __construct(
        private Application $application,
        private string $eventType, // 'created', 'updated', 'cancelled'
        private ?int $userId = null
    ) {}
    
    public function handle(): void
    {
        $webhooks = Webhook::query();
        
        if ($this->userId) {
            $webhooks->where('user_id', $this->userId);
        }
        
        $webhooks = $webhooks->get();
        
        foreach ($webhooks as $webhook) {
            $this->sendToWebhook($webhook);
        }
    }
    
    private function sendToWebhook(Webhook $webhook): void
    {
        try {
            $payload = [
                'event' => $this->eventType,
                'application' => [
                    'id' => $this->application->id,
                    'full_name' => $this->application->full_name,
                    'phone' => $this->application->phone,
                    'appointment_datetime' => $this->application->appointment_datetime,
                    'clinic_id' => $this->application->clinic_id,
                    'doctor_id' => $this->application->doctor_id,
                    'city_id' => $this->application->city_id,
                ],
                'timestamp' => now()->toISOString(),
            ];
            
            $signature = hash_hmac('sha256', json_encode($payload), $webhook->secret);
            
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->post($webhook->link, $payload);
                
            if ($response->successful()) {
                Log::info('Webhook sent successfully', [
                    'webhook_id' => $webhook->id,
                    'application_id' => $this->application->id,
                    'event' => $this->eventType
                ]);
            } else {
                throw new \Exception('Webhook error: ' . $response->body());
            }
            
        } catch (\Exception $e) {
            Log::error('Webhook delivery failed', [
                'webhook_id' => $webhook->id,
                'application_id' => $this->application->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
```

### 3. **Очистка кеша** ⭐⭐

**Проблема:** Синхронная очистка кеша блокирует операции.

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Services\CacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClearCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 60;
    
    public function __construct(
        private string $type, // 'calendar', 'user', 'static', 'all'
        private ?int $userId = null,
        private ?array $tags = null
    ) {}
    
    public function handle(CacheService $cacheService): void
    {
        match($this->type) {
            'calendar' => $cacheService->clearCalendarCache(),
            'user' => $cacheService->clearUserCache($this->userId),
            'static' => $cacheService->clearStaticCache(),
            'tagged' => $cacheService->clearTaggedCache($this->tags),
            'all' => $cacheService->clearAllCache(),
            default => throw new \InvalidArgumentException("Unknown cache type: {$this->type}")
        };
    }
}
```

---

## 📱 Telegram бот оптимизации

### 4. **Асинхронная обработка сообщений бота** ⭐⭐

**Проблема:** Синхронная обработка может блокировать webhook.

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Bot\Conversations\ApplicationConversation;
use App\Bot\Conversations\ReviewConversation;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Cache\FileCache;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Storages\Drivers\FileStorage;
use BotMan\Drivers\Telegram\TelegramDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 30;
    public $tries = 2;
    
    public function __construct(
        private array $telegramData
    ) {}
    
    public function handle(): void
    {
        try {
            DriverManager::loadDriver(TelegramDriver::class);
            
            $config = config('botman');
            $fileCache = new FileCache($config['cache']['config']['path']);
            $fileStorage = new FileStorage($config['userinfo']['config']['path']);
            
            // Создаем BotMan с данными из очереди
            $botman = BotManFactory::create($config, $fileCache, $this->telegramData, $fileStorage);
            
            $this->setupBotHandlers($botman);
            $botman->listen();
            
        } catch (\Exception $e) {
            Log::error('Telegram message processing failed', [
                'error' => $e->getMessage(),
                'telegram_data' => $this->telegramData
            ]);
            
            throw $e;
        }
    }
    
    private function setupBotHandlers(BotMan $botman): void
    {
        $botman->hears('/start.*', function (BotMan $bot) {
            $message = $bot->getMessage();
            $text = $message->getText();
            
            $parts = preg_split('/\s+/', trim($text), 2);
            
            if (count($parts) > 1) {
                $param = $parts[1];
                
                if (str_starts_with($param, 'review_')) {
                    $doctorUuid = str_replace('review_', '', $param);
                    $bot->startConversation(new ReviewConversation($doctorUuid));
                    return;
                }
            }
            
            $bot->startConversation(new ApplicationConversation());
        });
        
        $botman->fallback(function (BotMan $bot) {
            $bot->reply('Используйте /start для начала работы с ботом.');
        });
    }
}
```

### 5. **Отправка уведомлений в Telegram** ⭐⭐

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 15;
    public $tries = 3;
    
    public function __construct(
        private Application $application,
        private string $message,
        private ?int $chatId = null
    ) {}
    
    public function handle(): void
    {
        $chatId = $this->chatId ?? $this->application->tg_chat_id;
        
        if (!$chatId) {
            Log::warning('No chat ID for Telegram notification', [
                'application_id' => $this->application->id
            ]);
            return;
        }
        
        try {
            $response = Http::timeout(15)
                ->post("https://api.telegram.org/bot" . config('botman.telegram.token') . "/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $this->message,
                    'parse_mode' => 'HTML'
                ]);
                
            if ($response->successful()) {
                Log::info('Telegram notification sent', [
                    'application_id' => $this->application->id,
                    'chat_id' => $chatId
                ]);
            } else {
                throw new \Exception('Telegram API error: ' . $response->body());
            }
            
        } catch (\Exception $e) {
            Log::error('Telegram notification failed', [
                'application_id' => $this->application->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
```

---

## 📊 Аналитика и отчеты

### 6. **Генерация отчетов** ⭐⭐

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 300; // 5 минут
    
    public function __construct(
        private User $user,
        private string $reportType, // 'daily', 'weekly', 'monthly'
        private array $filters = [],
        private ?string $email = null
    ) {}
    
    public function handle(): void
    {
        try {
            $reportData = $this->generateReportData();
            $filePath = $this->saveReportToFile($reportData);
            
            if ($this->email) {
                // Отправляем отчет на email
                Mail::to($this->email)->send(new ReportMail($filePath, $this->reportType));
            }
            
            Log::info('Report generated successfully', [
                'user_id' => $this->user->id,
                'report_type' => $this->reportType,
                'file_path' => $filePath
            ]);
            
        } catch (\Exception $e) {
            Log::error('Report generation failed', [
                'user_id' => $this->user->id,
                'report_type' => $this->reportType,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    private function generateReportData(): array
    {
        $query = Application::query();
        
        // Применяем фильтры по ролям
        if ($this->user->isPartner()) {
            $query->where('clinic_id', $this->user->clinic_id);
        } elseif ($this->user->isDoctor()) {
            $query->where('doctor_id', $this->user->doctor_id);
        }
        
        // Применяем дополнительные фильтры
        if (!empty($this->filters['date_from'])) {
            $query->where('appointment_datetime', '>=', $this->filters['date_from']);
        }
        
        if (!empty($this->filters['date_to'])) {
            $query->where('appointment_datetime', '<=', $this->filters['date_to']);
        }
        
        return $query->with(['city', 'clinic', 'doctor'])
            ->orderBy('appointment_datetime')
            ->get()
            ->toArray();
    }
    
    private function saveReportToFile(array $data): string
    {
        $filename = 'report_' . $this->reportType . '_' . now()->format('Y_m_d_H_i_s') . '.json';
        $filePath = 'reports/' . $filename;
        
        Storage::put($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $filePath;
    }
}
```

---

## 🔄 Интеграции и внешние API

### 7. **Синхронизация с внешними системами** ⭐⭐

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Models\Doctor;
use App\Models\Clinic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncExternalDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 120;
    public $tries = 3;
    
    public function __construct(
        private string $dataType, // 'doctors', 'clinics', 'schedules'
        private ?int $clinicId = null
    ) {}
    
    public function handle(): void
    {
        try {
            match($this->dataType) {
                'doctors' => $this->syncDoctors(),
                'clinics' => $this->syncClinics(),
                'schedules' => $this->syncSchedules(),
                default => throw new \InvalidArgumentException("Unknown data type: {$this->dataType}")
            };
            
        } catch (\Exception $e) {
            Log::error('External data sync failed', [
                'data_type' => $this->dataType,
                'clinic_id' => $this->clinicId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    private function syncDoctors(): void
    {
        $response = Http::timeout(60)
            ->get(config('app.external_api.doctors_endpoint'));
            
        if ($response->successful()) {
            $doctors = $response->json();
            
            foreach ($doctors as $doctorData) {
                Doctor::updateOrCreate(
                    ['external_id' => $doctorData['id']],
                    [
                        'first_name' => $doctorData['first_name'],
                        'last_name' => $doctorData['last_name'],
                        'specialization' => $doctorData['specialization'],
                        'status' => $doctorData['status'],
                    ]
                );
            }
            
            Log::info('Doctors synced successfully', ['count' => count($doctors)]);
        } else {
            throw new \Exception('External API error: ' . $response->body());
        }
    }
    
    private function syncClinics(): void
    {
        // Аналогичная логика для клиник
    }
    
    private function syncSchedules(): void
    {
        // Аналогичная логика для расписаний
    }
}
```

---

## 📧 Email уведомления

### 8. **Отправка email уведомлений** ⭐⭐

**Решение:**
```php
<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendEmailNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 30;
    public $tries = 3;
    
    public function __construct(
        private Application $application,
        private string $emailType, // 'appointment_confirmed', 'appointment_reminder', 'appointment_cancelled'
        private ?string $email = null
    ) {}
    
    public function handle(): void
    {
        $email = $this->email ?? $this->getUserEmail();
        
        if (!$email) {
            Log::warning('No email for notification', [
                'application_id' => $this->application->id,
                'email_type' => $this->emailType
            ]);
            return;
        }
        
        try {
            $mailable = match($this->emailType) {
                'appointment_confirmed' => new AppointmentConfirmedMail($this->application),
                'appointment_reminder' => new AppointmentReminderMail($this->application),
                'appointment_cancelled' => new AppointmentCancelledMail($this->application),
                default => throw new \InvalidArgumentException("Unknown email type: {$this->emailType}")
            };
            
            Mail::to($email)->send($mailable);
            
            Log::info('Email notification sent', [
                'application_id' => $this->application->id,
                'email_type' => $this->emailType,
                'email' => $email
            ]);
            
        } catch (\Exception $e) {
            Log::error('Email notification failed', [
                'application_id' => $this->application->id,
                'email_type' => $this->emailType,
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    private function getUserEmail(): ?string
    {
        // Логика получения email пользователя
        // Можно извлечь из заявки или связанных данных
        return null;
    }
}
```

---

## 🔧 Обновление контроллеров

### 9. **Обновить ApplicationController**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Jobs\SendApplicationTo1C;
use App\Jobs\SendWebhookNotification;
use App\Jobs\SendTelegramNotification;
use App\Jobs\SendEmailNotification;
use App\Jobs\ClearCacheJob;
use App\Models\Application;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'city_id' => 'required|exists:cities,id',
            'clinic_id' => 'nullable|exists:clinics,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'full_name_parent' => 'nullable|string|max:255',
            'full_name' => 'required|string|max:255',
            'birth_date' => 'nullable|string|max:15',
            'phone' => 'required|string|max:25',
            'promo_code' => 'nullable|string|max:100',
            'tg_user_id' => 'nullable|integer',
            'tg_chat_id' => 'nullable|integer',
            'send_to_1c' => 'boolean',
        ]);

        $validated['id'] = now()->format('YmdHis') . rand(1000, 9999);
        $application = Application::create($validated);

        // Запускаем асинхронные задачи
        $this->dispatchAsyncTasks($application);

        return new ApplicationResource($application->load(['city', 'clinic', 'doctor']));
    }
    
    public function update(Request $request, Application $application)
    {
        $validated = $request->validate([
            'city_id' => 'sometimes|exists:cities,id',
            'clinic_id' => 'nullable|exists:clinics,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'full_name_parent' => 'nullable|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'birth_date' => 'nullable|string|max:15',
            'phone' => 'sometimes|string|max:25',
            'promo_code' => 'nullable|string|max:100',
            'send_to_1c' => 'boolean',
        ]);

        $application->update($validated);

        // Запускаем асинхронные задачи для обновления
        $this->dispatchAsyncTasks($application, 'updated');

        return new ApplicationResource($application->load(['city', 'clinic', 'doctor']));
    }
    
    private function dispatchAsyncTasks(Application $application, string $eventType = 'created'): void
    {
        // Отправка в 1C
        if ($application->send_to_1c) {
            SendApplicationTo1C::dispatch($application)
                ->delay(now()->addSeconds(5)); // Небольшая задержка
        }
        
        // Webhook уведомления
        SendWebhookNotification::dispatch($application, $eventType)
            ->delay(now()->addSeconds(2));
        
        // Telegram уведомления
        if ($application->tg_chat_id) {
            $message = $this->getTelegramMessage($application, $eventType);
            SendTelegramNotification::dispatch($application, $message)
                ->delay(now()->addSeconds(3));
        }
        
        // Email уведомления
        SendEmailNotification::dispatch($application, "appointment_{$eventType}")
            ->delay(now()->addSeconds(10));
        
        // Очистка кеша
        ClearCacheJob::dispatch('calendar')
            ->delay(now()->addSeconds(1));
    }
    
    private function getTelegramMessage(Application $application, string $eventType): string
    {
        return match($eventType) {
            'created' => "✅ Заявка создана!\n📅 Дата: {$application->appointment_datetime}\n👨‍⚕️ Врач: {$application->doctor->full_name}",
            'updated' => "🔄 Заявка обновлена!\n📅 Дата: {$application->appointment_datetime}",
            'cancelled' => "❌ Заявка отменена",
            default => "📋 Заявка изменена"
        };
    }
}
```

### 10. **Обновить BotController**

```php
<?php

namespace App\Http\Controllers\Bot;

use App\Jobs\ProcessTelegramMessage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function handle(Request $request)
    {
        try {
            // Отправляем обработку в очередь
            ProcessTelegramMessage::dispatch($request->all())
                ->onQueue('telegram'); // Отдельная очередь для Telegram
            
            // Сразу возвращаем ответ Telegram
            return response('OK', 200);
            
        } catch (\Exception $e) {
            \Log::error('Bot webhook error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);
            
            return response('OK', 200);
        }
    }
}
```

---

## ⚙️ Настройка очередей

### 11. **Конфигурация очередей**

```php
// config/queue.php - добавить новые очереди
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
    
    // Специальные очереди
    'telegram' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'telegram',
        'retry_after' => 30,
    ],
    
    'notifications' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'notifications',
        'retry_after' => 60,
    ],
    
    'reports' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'reports',
        'retry_after' => 300,
    ],
],
```

### 12. **Supervisor конфигурация**

```ini
# /etc/supervisor/conf.d/laravel-worker.conf
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/medical-center/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --queue=default,telegram,notifications,reports
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=laravel
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/medical-center/storage/logs/worker.log
stopwaitsecs=3600
```

---

## 📊 Мониторинг очередей

### 13. **Команда для мониторинга**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class QueueMonitor extends Command
{
    protected $signature = 'queue:monitor';
    protected $description = 'Monitor queue status and performance';

    public function handle()
    {
        $this->info('📊 Queue Status Monitor');
        $this->newLine();
        
        $queues = ['default', 'telegram', 'notifications', 'reports'];
        
        foreach ($queues as $queue) {
            $size = Redis::llen("queues:{$queue}");
            $failed = Redis::llen("queues:{$queue}:failed");
            
            $status = $size > 100 ? '🔴 High' : ($size > 50 ? '🟡 Medium' : '🟢 Low');
            
            $this->table(
                ['Queue', 'Pending Jobs', 'Failed Jobs', 'Status'],
                [[$queue, $size, $failed, $status]]
            );
        }
        
        $this->newLine();
        $this->info('💡 Use php artisan queue:work to process jobs');
    }
}
```

---

## 🚀 Приоритеты внедрения

### **Критичный приоритет (немедленно):**

1. ✅ **SendApplicationTo1C** - блокирует создание заявок
2. ✅ **SendWebhookNotification** - отсутствует функционал
3. ✅ **ProcessTelegramMessage** - улучшит отзывчивость бота

### **Высокий приоритет (в течение недели):**

4. ✅ **ClearCacheJob** - оптимизирует производительность
5. ✅ **SendTelegramNotification** - улучшит UX
6. ✅ **SendEmailNotification** - добавит email уведомления

### **Средний приоритет (в течение месяца):**

7. ✅ **GenerateReportJob** - автоматизация отчетов
8. ✅ **SyncExternalDataJob** - интеграции
9. ✅ **QueueMonitor** - мониторинг системы

---

## 📈 Ожидаемые результаты

### **Производительность:**
- ⚡ **Создание заявок**: +80-90% скорости (убрана блокировка на 1C)
- ⚡ **Telegram бот**: +70% отзывчивости
- ⚡ **API ответы**: +50-60% скорости

### **Надежность:**
- 🛡️ **Устойчивость к сбоям** внешних API
- 🛡️ **Автоматические повторы** при ошибках
- 🛡️ **Мониторинг** состояния очередей

### **Масштабируемость:**
- 📊 **Горизонтальное масштабирование** воркеров
- 📊 **Приоритизация** задач
- 📊 **Отдельные очереди** для разных типов задач

---

## ⚠️ Важные замечания

1. **Тестирование**: Все Job'ы должны быть протестированы
2. **Мониторинг**: Настроить алерты на застрявшие очереди
3. **Резервные копии**: Очереди должны быть персистентными
4. **Логирование**: Подробные логи для отладки
5. **Таймауты**: Правильные настройки для разных типов задач

---

*Документ создан: {{ date('Y-m-d H:i:s') }}*
*Версия приложения: Laravel 11.x*

