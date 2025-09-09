# Рекомендации по оптимизации приложения Medical Center

## 📊 Общий анализ

Приложение представляет собой систему записи к врачу с Telegram ботом и админ-панелью на Filament. Архитектура хорошо структурирована, но есть множество возможностей для оптимизации производительности и улучшения кода.

---

## 🚨 Критичные проблемы производительности

### 1. **N+1 проблемы в запросах**

**Проблемы найдены:**

#### `ApplicationResource.php` (строки 47-49)
```php
$clinic = Clinic::query()->where('id', $user->clinic_id)->first();
$applications = $clinic->applications->pluck('id')->toArray();
```
**Проблема:** Загружается вся клиника, затем все её заявки в память.

**Решение:**
```php
$applicationIds = Application::where('clinic_id', $user->clinic_id)->pluck('id');
$query->whereIn('id', $applicationIds);
```

#### `DoctorController.php` (строки 29, 36)
```php
$doctors = $clinic->doctors()->where(...)->get();
$doctors = $city->allDoctors()->where(...)->get();
```
**Проблема:** Отсутствует предзагрузка связей.

**Решение:**
```php
$doctors = $clinic->doctors()->with(['clinics', 'branches'])->where(...)->get();
```

#### `User.php` (строки 113-115, 119-121, 137-139, 143)
```php
return \App\Models\Cabinet::whereHas('branch', function($query) {
    $query->where('clinic_id', $this->clinic_id);
})->get();
```
**Проблема:** Множественные запросы без кеширования.

**Решение:** Вынести в сервис с кешированием.

### 2. **Тяжелые запросы в календаре**

#### `AppointmentCalendarWidget.php` (строка 462)
```php
$shift = DoctorShift::with(['cabinet.branch.clinic', 'cabinet.branch.city', 'doctor'])
    ->find($extendedProps['shift_id']);
```
**Проблема:** Избыточная загрузка связей для простого поиска.

**Решение:** Использовать `select()` для ограничения полей.

#### `CalendarEventService.php` (строка 42)
```php
$shifts = $shiftsQuery->get();
```
**Проблема:** Загрузка всех смен в память без пагинации.

**Решение:** Использовать `chunk()` или `lazy()`.

---

## 🔧 Оптимизация запросов к БД

### 1. **Добавить недостающие индексы**

```sql
-- Для оптимизации календарных запросов
CREATE INDEX idx_doctor_shifts_cabinet_datetime ON doctor_shifts (cabinet_id, start_time);
CREATE INDEX idx_applications_cabinet_datetime ON applications (cabinet_id, appointment_datetime);
CREATE INDEX idx_applications_clinic_datetime ON applications (clinic_id, appointment_datetime);

-- Для фильтрации по ролям
CREATE INDEX idx_cabinets_branch_clinic ON cabinets (branch_id);
CREATE INDEX idx_branches_clinic_city ON branches (clinic_id, city_id);

-- Для поиска врачей
CREATE INDEX idx_doctors_age_admission ON doctors (age_admission_from, age_admission_to);
CREATE INDEX idx_doctors_status ON doctors (status);
```

### 2. **Оптимизировать whereHas запросы**

**Проблема:** Множественные `whereHas` без индексов.

**Решение:** Заменить на JOIN'ы где возможно:

```php
// Вместо:
$doctors = Doctor::whereHas('clinics.cities', function ($q) use ($cityId) {
    $q->where('cities.id', $cityId);
})->get();

// Использовать:
$doctors = Doctor::join('clinic_doctor', 'doctors.id', '=', 'clinic_doctor.doctor_id')
    ->join('clinics', 'clinic_doctor.clinic_id', '=', 'clinics.id')
    ->join('clinic_city', 'clinics.id', '=', 'clinic_city.clinic_id')
    ->where('clinic_city.city_id', $cityId)
    ->select('doctors.*')
    ->distinct()
    ->get();
```

---

## 💾 Кеширование

### 1. **Расширить кеширование календаря**

**Текущее состояние:** Есть базовая система кеширования календаря.

**Улучшения:**

```php
// Добавить в CalendarEventService
public function getCachedEvents(array $fetchInfo, array $filters, User $user): array
{
    $cacheKey = $this->generateCacheKey($fetchInfo, $filters, $user);
    
    return Cache::remember($cacheKey, 300, function() use ($fetchInfo, $filters, $user) {
        return $this->generateEvents($fetchInfo, $filters, $user);
    });
}

private function generateCacheKey(array $fetchInfo, array $filters, User $user): string
{
    return sprintf(
        'calendar_events_%s_%s_%s_%s',
        $user->id,
        md5(serialize($fetchInfo)),
        md5(serialize($filters)),
        $user->getRoleNames()->implode('_')
    );
}
```

### 2. **Кешировать статические данные**

```php
// В CityController
public function index(Request $request)
{
    $cacheKey = 'cities_active_' . ($request->get('size', 20));
    
    return Cache::remember($cacheKey, 3600, function() use ($request) {
        $query = City::where('status', 1);
        $perPage = $request->get('size', 20);
        $cities = $query->orderBy('name')->paginate($perPage);
        
        return CityResource::collection($cities);
    });
}

// В DoctorController
public function index()
{
    return Cache::remember('doctors_active_paginated', 1800, function() {
        $doctors = Doctor::where('status', 1)
            ->with(['applications', 'clinics', 'reviews'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        return DoctorResource::collection($doctors);
    });
}
```

### 3. **Кешировать пользовательские права доступа**

```php
// В User.php
public function getAccessibleCabinets()
{
    $cacheKey = "user_cabinets_{$this->id}";
    
    return Cache::remember($cacheKey, 1800, function() {
        if ($this->isSuperAdmin()) {
            return Cabinet::all();
        }
        
        if ($this->isPartner()) {
            return Cabinet::whereHas('branch', function($query) {
                $query->where('clinic_id', $this->clinic_id);
            })->get();
        }
        
        if ($this->isDoctor()) {
            return Cabinet::whereHas('branch.doctors', function($query) {
                $query->where('doctor_id', $this->doctor_id);
            })->get();
        }
        
        return collect();
    });
}
```

---

## 🏗️ Рефакторинг архитектуры

### 1. **Создать новые сервисы**

#### `ApplicationService.php`
```php
<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ApplicationService
{
    public function getApplicationsForUser(User $user, array $filters = [])
    {
        $cacheKey = "user_applications_{$user->id}_" . md5(serialize($filters));
        
        return Cache::remember($cacheKey, 300, function() use ($user, $filters) {
            $query = Application::with(['city', 'clinic', 'doctor', 'branch', 'cabinet']);
            
            if ($user->isPartner()) {
                $query->where('clinic_id', $user->clinic_id);
            } elseif ($user->isDoctor()) {
                $query->where('doctor_id', $user->doctor_id);
            }
            
            return $query->orderBy('created_at', 'desc')->paginate(15);
        });
    }
    
    public function createApplication(array $data, ?int $tgUserId = null): Application
    {
        $data['id'] = now()->format('YmdHis') . rand(1000, 9999);
        
        if ($tgUserId) {
            $data['tg_user_id'] = $tgUserId;
        }
        
        $application = Application::create($data);
        
        // Очищаем кеш
        $this->clearUserApplicationsCache($tgUserId);
        
        return $application->load(['city', 'clinic', 'doctor']);
    }
    
    private function clearUserApplicationsCache(?int $userId): void
    {
        if ($userId) {
            Cache::forget("user_applications_{$userId}_*");
        }
    }
}
```

#### `DoctorService.php`
```php
<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\City;
use App\Models\Clinic;
use Illuminate\Support\Facades\Cache;

class DoctorService
{
    public function getDoctorsByCity(City $city, ?int $age = null)
    {
        $cacheKey = "doctors_city_{$city->id}_age_" . ($age ?? 'all');
        
        return Cache::remember($cacheKey, 1800, function() use ($city, $age) {
            $query = $city->allDoctors()->with(['clinics', 'branches']);
            
            if ($age) {
                $query->where('age_admission_from', '<=', $age)
                      ->where('age_admission_to', '>=', $age);
            }
            
            return $query->get();
        });
    }
    
    public function getDoctorsByClinic(Clinic $clinic, ?int $age = null)
    {
        $cacheKey = "doctors_clinic_{$clinic->id}_age_" . ($age ?? 'all');
        
        return Cache::remember($cacheKey, 1800, function() use ($clinic, $age) {
            $query = $clinic->doctors()->with(['clinics', 'branches']);
            
            if ($age) {
                $query->where('age_admission_from', '<=', $age)
                      ->where('age_admission_to', '>=', $age);
            }
            
            return $query->get();
        });
    }
}
```

#### `CacheService.php`
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    public function clearCalendarCache(): void
    {
        $keys = Cache::get('calendar_cache_keys', []);
        
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        
        Cache::forget('calendar_cache_keys');
    }
    
    public function clearUserCache(int $userId): void
    {
        $patterns = [
            "user_applications_{$userId}_*",
            "user_cabinets_{$userId}",
            "user_shifts_{$userId}",
            "calendar_events_{$userId}_*"
        ];
        
        foreach ($patterns as $pattern) {
            $this->clearCacheByPattern($pattern);
        }
    }
    
    public function clearStaticCache(): void
    {
        $staticKeys = [
            'cities_active_*',
            'doctors_active_paginated',
            'clinics_active_*'
        ];
        
        foreach ($staticKeys as $pattern) {
            $this->clearCacheByPattern($pattern);
        }
    }
    
    private function clearCacheByPattern(string $pattern): void
    {
        // Реализация очистки по паттерну зависит от драйвера кеша
        // Для Redis можно использовать SCAN
    }
}
```

### 2. **Оптимизировать контроллеры**

#### `ApplicationController.php`
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Services\ApplicationService;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationService $applicationService
    ) {}
    
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

        $application = $this->applicationService->createApplication(
            $validated, 
            $validated['tg_user_id'] ?? null
        );

        return new ApplicationResource($application);
    }
}
```

### 3. **Улучшить модели**

#### Добавить скоупы в модели
```php
// В Doctor.php
public function scopeActive($query)
{
    return $query->where('status', 1);
}

public function scopeByAge($query, int $age)
{
    return $query->where('age_admission_from', '<=', $age)
                 ->where('age_admission_to', '>=', $age);
}

// В Application.php
public function scopeForUser($query, User $user)
{
    if ($user->isPartner()) {
        return $query->where('clinic_id', $user->clinic_id);
    } elseif ($user->isDoctor()) {
        return $query->where('doctor_id', $user->doctor_id);
    }
    
    return $query;
}
```

---

## 📱 Оптимизация Telegram бота

### 1. **Кешировать данные бота**

```php
// В ApplicationConversation.php
public function askCity()
{
    $cacheKey = 'bot_cities_active';
    
    $cities = Cache::remember($cacheKey, 3600, function() {
        return City::where('status', 1)->orderBy('name')->get();
    });
    
    // ... остальная логика
}

public function askClinic()
{
    $cityId = $this->applicationData['city_id'];
    $cacheKey = "bot_clinics_city_{$cityId}";
    
    $clinics = Cache::remember($cacheKey, 1800, function() use ($cityId) {
        return Clinic::whereHas('cities', function ($query) use ($cityId) {
            $query->where('city_id', $cityId);
        })->where('status', 1)->get();
    });
    
    // ... остальная логика
}
```

### 2. **Оптимизировать запросы врачей**

```php
// В ApplicationConversation.php
public function askDoctor()
{
    $cityId = $this->applicationData['city_id'];
    $clinicId = $this->applicationData['clinic_id'] ?? null;
    $cacheKey = "bot_doctors_city_{$cityId}_clinic_" . ($clinicId ?? 'all');
    
    $doctors = Cache::remember($cacheKey, 1800, function() use ($cityId, $clinicId) {
        $query = Doctor::whereHas('clinics.cities', function ($q) use ($cityId) {
            $q->where('city_id', $cityId);
        })->where('status', 1);
        
        if ($clinicId) {
            $query->whereHas('clinics', function ($q) use ($clinicId) {
                $q->where('clinic_id', $clinicId);
            });
        }
        
        return $query->get();
    });
    
    // ... остальная логика
}
```

---

## 🎯 Оптимизация Filament админки

### 1. **Оптимизировать виджеты календаря**

```php
// В AppointmentCalendarWidget.php
public function getEvents(array $fetchInfo): array
{
    $user = auth()->user();
    $cacheKey = $this->generateEventsCacheKey($fetchInfo, $user);
    
    return Cache::remember($cacheKey, 300, function() use ($fetchInfo, $user) {
        return $this->getEventService()->generateEvents($fetchInfo, $this->filters, $user);
    });
}

private function generateEventsCacheKey(array $fetchInfo, User $user): string
{
    return sprintf(
        'calendar_widget_events_%s_%s_%s_%s',
        $user->id,
        md5(serialize($fetchInfo)),
        md5(serialize($this->filters)),
        $user->getRoleNames()->implode('_')
    );
}
```

### 2. **Оптимизировать фильтры**

```php
// В CalendarFilterService.php
public function getCachedClinics(User $user): array
{
    $cacheKey = "filter_clinics_{$user->id}";
    
    return Cache::remember($cacheKey, 1800, function() use ($user) {
        return $this->getClinics($user);
    });
}

public function getCachedBranches(array $clinicIds, User $user): array
{
    $cacheKey = "filter_branches_" . md5(serialize($clinicIds)) . "_{$user->id}";
    
    return Cache::remember($cacheKey, 1800, function() use ($clinicIds, $user) {
        return $this->getBranches($clinicIds, $user);
    });
}
```

---

## 🔄 Асинхронная обработка

### 1. **Создать очереди для тяжелых операций**

```php
// Создать Job для отправки в 1C
<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendApplicationTo1C implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(
        private Application $application
    ) {}
    
    public function handle(): void
    {
        // Логика отправки в 1C
        // Очистка кеша после отправки
    }
}

// В ApplicationController
public function store(Request $request)
{
    $application = $this->applicationService->createApplication($validated);
    
    // Отправляем в очередь
    SendApplicationTo1C::dispatch($application);
    
    return new ApplicationResource($application);
}
```

### 2. **Создать Job для очистки кеша**

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
    
    public function __construct(
        private string $type,
        private ?int $userId = null
    ) {}
    
    public function handle(CacheService $cacheService): void
    {
        match($this->type) {
            'calendar' => $cacheService->clearCalendarCache(),
            'user' => $cacheService->clearUserCache($this->userId),
            'static' => $cacheService->clearStaticCache(),
            default => throw new \InvalidArgumentException("Unknown cache type: {$this->type}")
        };
    }
}
```

---

## 📊 Мониторинг и метрики

### 1. **Добавить логирование медленных запросов**

```php
// В AppServiceProvider.php
public function boot()
{
    if (app()->environment('production')) {
        DB::listen(function ($query) {
            if ($query->time > 1000) { // Запросы дольше 1 секунды
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time
                ]);
            }
        });
    }
}
```

### 2. **Добавить метрики производительности**

```php
// Создать Middleware для метрик
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PerformanceMetrics
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $response = $next($request);
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $executionTime = ($endTime - $startTime) * 1000; // ms
        $memoryUsage = ($endMemory - $startMemory) / 1024 / 1024; // MB
        
        if ($executionTime > 500 || $memoryUsage > 10) { // Логируем медленные запросы
            Log::info('Performance metrics', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'execution_time' => round($executionTime, 2),
                'memory_usage' => round($memoryUsage, 2),
                'user_id' => auth()->id()
            ]);
        }
        
        return $response;
    }
}
```

---

## 🚀 Приоритетные задачи для реализации

### **Высокий приоритет (критично для производительности):**

1. ✅ **Исправить N+1 проблемы** в `ApplicationResource.php` и `User.php`
2. ✅ **Добавить индексы** для календарных запросов
3. ✅ **Оптимизировать whereHas запросы** в контроллерах
4. ✅ **Расширить кеширование** статических данных

### **Средний приоритет (улучшение архитектуры):**

5. ✅ **Создать ApplicationService** и **DoctorService**
6. ✅ **Оптимизировать Telegram бот** с кешированием
7. ✅ **Улучшить виджеты календаря** с кешированием
8. ✅ **Добавить скоупы** в модели

### **Низкий приоритет (долгосрочные улучшения):**

9. ✅ **Создать асинхронные очереди** для тяжелых операций
10. ✅ **Добавить мониторинг** производительности
11. ✅ **Создать CacheService** для централизованного управления кешем

---

## 📈 Ожидаемые результаты

### **Производительность:**
- ⚡ **Скорость загрузки календаря**: +60-80%
- ⚡ **Время ответа API**: +40-60%
- ⚡ **Память**: -30-50% использования
- ⚡ **Нагрузка на БД**: -50-70%

### **Пользовательский опыт:**
- 🚀 **Быстрая работа админки** с большими объемами данных
- 🚀 **Отзывчивый Telegram бот** даже при высокой нагрузке
- 🚀 **Стабильная работа** при росте пользователей

### **Масштабируемость:**
- 📊 **Поддержка большего количества** врачей и заявок
- 📊 **Готовность к росту** пользователей
- 📊 **Устойчивость к пиковым нагрузкам**

---

## ⚠️ Важные замечания

1. **Тестирование**: Все изменения необходимо тестировать на копии продакшена
2. **Постепенное внедрение**: Реализовывать оптимизации поэтапно
3. **Мониторинг**: Отслеживать производительность после каждого изменения
4. **Резервные копии**: Создавать бэкапы перед внедрением изменений
5. **Кеширование**: Учитывать инвалидацию кеша при обновлении данных

---

*Документ создан: {{ date('Y-m-d H:i:s') }}*
*Версия приложения: Laravel 11.x*

