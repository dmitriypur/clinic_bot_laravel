<?php

namespace App\Models;

use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApplicationStatus extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
        'sort_order',
        'is_active',
        'type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Связь с заявками
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'status_id');
    }

    /**
     * Возвращает цвет бейджа для компонентов Filament
     */
    public function getBadgeColor(): array
    {
        return match ($this->color) {
            'blue' => Color::Blue,
            'green' => Color::Green,
            'red' => Color::Red,
            'yellow' => Color::Amber,
            'purple' => Color::Purple,
            'pink' => Color::Pink,
            'indigo' => Color::Indigo,
            default => Color::Gray,
        };
    }

    /**
     * Получить активные статусы, отсортированные по порядку
     */
    public static function getActiveStatuses()
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Получить статус по slug
     */
    public static function getBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Проверить, является ли статус "Новая"
     */
    public function isNew(): bool
    {
        return $this->slug === 'new';
    }

    /**
     * Проверить, является ли статус "Записан"
     */
    public function isScheduled(): bool
    {
        return $this->slug === 'scheduled';
    }

    /**
     * Проверить, является ли статус "Отменен"
     */
    public function isCancelled(): bool
    {
        return $this->slug === 'cancelled';
    }

    /**
     * Проверить, является ли статус подтверждением записи на прием.
     */
    public function isAppointmentConfirmed(): bool
    {
        $slug = Str::of((string) $this->slug)
            ->trim()
            ->lower()
            ->replace('-', '_')
            ->value();

        $confirmedSlugs = [
            'appointment_confirmed',
            'appointment_confirm',
            'appointment_confirmation',
            'confirmed',
            'status_confirmed',
        ];

        if ($slug && in_array($slug, $confirmedSlugs, true)) {
            return true;
        }

        $name = Str::lower((string) $this->name);

        if ($name === '') {
            return false;
        }

        $negativeMarkers = ['не подтверж', 'неподтверж', 'not confirm', 'cancel'];

        foreach ($negativeMarkers as $marker) {
            if (Str::contains($name, $marker)) {
                return false;
            }
        }

        return Str::contains($name, ['подтвержд', 'confirm']);
    }
}
