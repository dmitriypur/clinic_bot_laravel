<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';

    /**
     * Получить человекочитаемое название статуса
     */
    public function getLabel(): string
    {
        return match($this) {
            self::IN_PROGRESS => 'В процессе',
            self::COMPLETED => 'Завершен',
        };
    }

    /**
     * Получить цвет для статуса
     */
    public function getColor(): string
    {
        return match($this) {
            self::IN_PROGRESS => 'warning',
            self::COMPLETED => 'success',
        };
    }

    /**
     * Получить иконку для статуса
     */
    public function getIcon(): string
    {
        return match($this) {
            self::IN_PROGRESS => 'heroicon-o-clock',
            self::COMPLETED => 'heroicon-o-check-circle',
        };
    }

    /**
     * Получить все статусы в виде массива для select
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($status) => [$status->value => $status->getLabel()])
            ->toArray();
    }
}
