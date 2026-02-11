<?php

namespace App\Models;

use App\Jobs\SendAppointmentConfirmationNotification;
use App\Jobs\SendAppointmentReminderNotification;
use App\Services\Crm\CrmNotificationService;
use App\Traits\HasCalendarOptimizations;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Application extends Model
{
    use HasCalendarOptimizations;

    public $incrementing = true;

    protected $keyType = "integer";

    // Константы статусов приема (устаревшие, используйте ApplicationStatus)
    const STATUS_SCHEDULED = "scheduled";

    const STATUS_IN_PROGRESS = "in_progress";

    const STATUS_COMPLETED = "completed";

    // Slug-и статусов (новая система статусов заявок)
    public const STATUS_SLUG_APPOINTMENT_SCHEDULED = "appointment_scheduled";

    public const STATUS_SLUG_APPOINTMENT_IN_PROGRESS = "appointment_in_progress";

    public const STATUS_SLUG_APPOINTMENT_COMPLETED = "appointment_completed";

    public const STATUS_SLUG_APPOINTMENT_CONFIRMED = "appointment_confirmed";

    // Константы источников создания заявки
    const SOURCE_TELEGRAM = "telegram";

    const SOURCE_FRONTEND = "frontend";

    public const INTEGRATION_TYPE_LOCAL = "local";

    public const INTEGRATION_TYPE_ONEC = "onec";

    protected $fillable = [
        "city_id",
        "clinic_id",
        "branch_id",
        "doctor_id",
        "cabinet_id",
        "appointment_datetime",
        "full_name_parent",
        "full_name",
        "birth_date",
        "phone",
        "promo_code",
        "tg_user_id",
        "tg_chat_id",
        "send_to_1c",
        "appointment_status",
        "source",
        "status_id",
        "external_appointment_id",
        "integration_type",
        "integration_status",
        "integration_payload",
    ];

    protected $casts = [
        "id" => "integer",
        "city_id" => "integer",
        "clinic_id" => "integer",
        "branch_id" => "integer",
        "doctor_id" => "integer",
        "cabinet_id" => "integer",
        "tg_user_id" => "integer",
        "tg_chat_id" => "integer",
        "send_to_1c" => "boolean",
        "appointment_status" => "string",
        "status_id" => "integer",
        "integration_payload" => "array",
    ];

    protected function appointmentDatetime(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$value) {
                    return null;
                }

                $timezone = config("app.timezone", "UTC");

                return Carbon::parse($value, "UTC")->setTimezone($timezone);
            },
            set: function ($value) {
                if (empty($value)) {
                    return null;
                }

                $timezone = config("app.timezone", "UTC");

                $date =
                    $value instanceof Carbon
                        ? $value->copy()
                        : Carbon::parse($value, $timezone);

                return $date->setTimezone("UTC");
            },
        );
    }

    /**
     * Boot the model and register event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Очищаем кэш календаря при создании заявки
        static::created(function ($application) {
            static::clearCalendarCache();
            $application->notifyTelegramAboutAppointmentConfirmation();
            app(CrmNotificationService::class)->dispatch($application);
        });

        static::saving(function (self $application) {
            // Автоматически выставляем клинику по выбранному филиалу (актуально для заявок из WebApp)
            if (!$application->clinic_id && $application->branch_id) {
                $clinicId = Branch::query()
                    ->whereKey($application->branch_id)
                    ->value("clinic_id");

                if ($clinicId) {
                    $application->clinic_id = $clinicId;
                }
            }
        });

        // Очищаем кэш календаря при обновлении заявки
        static::updated(function ($application) {
            static::clearCalendarCache();

            if ($application->wasChanged("status_id")) {
                $application->notifyTelegramAboutAppointmentConfirmation();
            }

            if ($application->wasChanged("appointment_datetime")) {
                $application->scheduleTelegramReminderNotification();
            }

            if (
                $application->wasChanged("clinic_id") &&
                $application->clinic_id
            ) {
                app(CrmNotificationService::class)->dispatch($application);
            }

            // Автоматически меняем статус на "Создана" при назначении статуса "Запись на прием"
            // if ($application->wasChanged('status_id')) {
            //     $newStatus = $application->status;
            //     if ($newStatus && $newStatus->slug === 'appointment') {
            //         $createdStatus = ApplicationStatus::where('slug', 'appointment_scheduled')->first();
            //         if ($createdStatus) {
            //             $application->status_id = $createdStatus->id;
            //             $application->saveQuietly(); // saveQuietly чтобы избежать рекурсии
            //         }
            //     }
            // }
        });

        // Очищаем кэш календаря при удалении заявки
        static::deleted(function ($application) {
            static::clearCalendarCache();
        });

        static::retrieved(function (self $application) {
            $application->autoCompleteIfSlotExpired();
        });
    }

    /**
     * Очищает кэш календаря
     */
    protected static function clearCalendarCache(): void
    {
        // Очищаем все ключи кэша календаря
        $keys = Cache::get("calendar_cache_keys", []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        // Очищаем ключ со списком ключей
        Cache::forget("calendar_cache_keys");
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ApplicationStatus::class, "status_id");
    }

    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }

    public function usesExternalIntegration(): bool
    {
        return $this->integration_type &&
            $this->integration_type !== self::INTEGRATION_TYPE_LOCAL;
    }

    /**
     * Проверяет, запланирован ли прием
     */
    public function isScheduled(): bool
    {
        return $this->appointment_status === self::STATUS_SCHEDULED;
    }

    /**
     * Проверяет, идет ли прием в данный момент
     */
    public function isInProgress(): bool
    {
        return $this->appointment_status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Проверяет, завершен ли прием
     */
    public function isCompleted(): bool
    {
        return $this->appointment_status === self::STATUS_COMPLETED;
    }

    /**
     * Начинает прием
     */
    public function startAppointment(): bool
    {
        if ($this->isScheduled() && !$this->appointment) {
            // Создаем новый прием
            $appointment = $this->appointment()->create([
                "status" => \App\Enums\AppointmentStatus::IN_PROGRESS,
                "started_at" => now(),
            ]);

            if ($appointment) {
                $this->appointment_status = self::STATUS_IN_PROGRESS;
                $this->fillStatusFromSlug(
                    [
                        self::STATUS_SLUG_APPOINTMENT_IN_PROGRESS,
                        "appointment_in-progress",
                        "appointment_started",
                        "appointment-started",
                        "in_progress",
                    ],
                    [
                        "Идет прием",
                        "Идёт прием",
                        "Идёт приём",
                        "Прием в процессе",
                    ],
                );

                return $this->save();
            }
        }

        return false;
    }

    /**
     * Завершает прием
     */
    public function completeAppointment(): bool
    {
        if ($this->isInProgress() && $this->appointment) {
            // Завершаем прием
            $appointmentCompleted = $this->appointment->complete();

            if ($appointmentCompleted) {
                $this->applyCompletedStatus();

                return $this->save();
            }
        }

        return false;
    }

    /**
     * Возвращает человекочитаемое название статуса
     */
    public function getStatusLabel(): string
    {
        return match ($this->appointment_status) {
            self::STATUS_SCHEDULED => "Запланирован",
            self::STATUS_IN_PROGRESS => "В процессе",
            self::STATUS_COMPLETED => "Завершен",
            default => "Неизвестно",
        };
    }

    /**
     * Возвращает человекочитаемое название источника
     */
    public function getSourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_TELEGRAM => "Telegram бот",
            self::SOURCE_FRONTEND => "Фронтенд форма",
            null => "Админ-панель",
            default => "Неизвестно",
        };
    }

    /**
     * Возвращает название статуса заявки
     */
    public function getStatusName(): string
    {
        return $this->status?->name ?? "Не указан";
    }

    /**
     * Возвращает цвет статуса заявки
     */
    public function getStatusColor(): string
    {
        return $this->status?->color ?? "gray";
    }

    /**
     * Проверяет, является ли заявка новой
     */
    public function isNew(): bool
    {
        return $this->status?->isNew() ?? false;
    }

    /**
     * Проверяет, является ли заявка записанной
     */
    public function isScheduledStatus(): bool
    {
        return $this->status?->isScheduled() ?? false;
    }

    /**
     * Проверяет, является ли заявка отмененной
     */
    public function isCancelled(): bool
    {
        return $this->status?->isCancelled() ?? false;
    }

    /**
     * Устанавливает статус заявки по slug
     */
    public function setStatusBySlug(string $slug): bool
    {
        $status = ApplicationStatus::getBySlug($slug);
        if ($status) {
            $this->status_id = $status->id;

            return $this->save();
        }

        return false;
    }

    /**
     * Устанавливает идентификатор статуса без сохранения записи.
     */
    public function fillStatusFromSlug(
        string|array|null $slug,
        ?array $fallbackNames = null,
    ): void {
        $slugs = array_filter(Arr::wrap($slug));

        foreach ($slugs as $candidate) {
            if ($statusId = self::resolveStatusIdBySlug($candidate)) {
                $this->status_id = $statusId;

                return;
            }
        }

        if (empty($fallbackNames)) {
            return;
        }

        foreach ($fallbackNames as $name) {
            $name = trim((string) $name);

            if ($name === "") {
                continue;
            }

            if ($statusId = self::resolveStatusIdByName($name)) {
                $this->status_id = $statusId;

                return;
            }
        }
    }

    protected function notifyTelegramAboutAppointmentConfirmation(): void
    {
        if (!$this->status_id) {
            return;
        }

        $status = $this->status;

        if (!$status || $status->getKey() !== $this->status_id) {
            $status = ApplicationStatus::find($this->status_id);
        }

        if (!$this->shouldNotifyTelegramOnStatus($status)) {
            return;
        }

        SendAppointmentConfirmationNotification::dispatch($this->getKey());

        $this->scheduleTelegramReminderNotification();
    }

    protected function shouldNotifyTelegramOnStatus(
        ?ApplicationStatus $status,
    ): bool {
        if (!$status) {
            return false;
        }

        if (empty($this->tg_chat_id)) {
            return false;
        }

        if ($status->type && $status->type !== "appointment") {
            return false;
        }

        return $status->isAppointmentConfirmed();
    }

    protected function scheduleTelegramReminderNotification(): void
    {
        if (!$this->status_id) {
            return;
        }

        $status = $this->status;

        if (!$status || $status->getKey() !== $this->status_id) {
            $status = ApplicationStatus::find($this->status_id);
        }

        if (!$this->shouldNotifyTelegramOnStatus($status)) {
            return;
        }

        $appointmentDateTime = $this->appointment_datetime;

        if (!$appointmentDateTime instanceof Carbon) {
            return;
        }

        if ($appointmentDateTime->isPast()) {
            return;
        }

        $reminderTime = $appointmentDateTime->copy()->subHours(2);
        $now = Carbon::now($appointmentDateTime->getTimezone());

        $appointmentUtcIso = $appointmentDateTime
            ->copy()
            ->setTimezone("UTC")
            ->toIso8601String();

        if ($reminderTime->lessThanOrEqualTo($now)) {
            SendAppointmentReminderNotification::dispatch(
                $this->getKey(),
                $appointmentUtcIso,
            );

            return;
        }

        SendAppointmentReminderNotification::dispatch(
            $this->getKey(),
            $appointmentUtcIso,
        )->delay($reminderTime);
    }

    /**
     * Возвращает ID статуса по slug с кешированием на время запроса.
     */
    protected static function resolveStatusIdBySlug(?string $slug): ?int
    {
        static $cache = [];

        if (!$slug) {
            return null;
        }

        if (!array_key_exists($slug, $cache)) {
            $cache[$slug] = ApplicationStatus::getBySlug($slug)?->id;
        }

        return $cache[$slug];
    }

    protected static function resolveStatusIdByName(string $name): ?int
    {
        static $cache = [];

        if (!array_key_exists($name, $cache)) {
            $cache[$name] = ApplicationStatus::query()
                ->where("type", "appointment")
                ->where("name", $name)
                ->value("id");
        }

        return $cache[$name];
    }

    /**
     * Автоматически завершаем прием, если время слота прошло, а прием не начат.
     */
    public function autoCompleteIfSlotExpired(): bool
    {
        if (!$this->shouldAutoCompleteBecauseSlotExpired()) {
            return false;
        }

        $this->applyCompletedStatus();

        return $this->save();
    }

    protected function shouldAutoCompleteBecauseSlotExpired(): bool
    {
        if (!$this->isScheduled()) {
            return false;
        }

        $appointmentDateTime = $this->appointment_datetime;

        if (!$appointmentDateTime instanceof Carbon) {
            return false;
        }

        $now = now($appointmentDateTime->getTimezone());

        if (!$now->greaterThan($appointmentDateTime)) {
            return false;
        }

        return true;
    }

    /**
     * Устанавливает статус "Прием завершен" и подбирает соответствующий ID.
     */
    protected function applyCompletedStatus(): void
    {
        $this->appointment_status = self::STATUS_COMPLETED;

        $this->fillStatusFromSlug(
            [
                self::STATUS_SLUG_APPOINTMENT_COMPLETED,
                "appointment-completed",
                "appointment_done",
                "completed",
            ],
            [
                "Прием проведен",
                "Приём проведен",
                "Приём проведён",
                "Прием завершен",
            ],
        );

        $this->unsetRelation("status");
    }

    /**
     * Scope для фильтрации по статусу
     */
    public function scopeWithStatus($query, string $slug)
    {
        return $query->whereHas("status", function ($q) use ($slug) {
            $q->where("slug", $slug);
        });
    }

    /**
     * Scope для активных статусов
     */
    public function scopeWithActiveStatus($query)
    {
        return $query->whereHas("status", function ($q) {
            $q->where("is_active", true);
        });
    }
}
