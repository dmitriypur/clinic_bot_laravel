<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationStatus extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
        'sort_order',
        'is_active',
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
}