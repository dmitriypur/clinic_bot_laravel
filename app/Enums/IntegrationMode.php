<?php

declare(strict_types=1);

namespace App\Enums;

enum IntegrationMode: string
{
    case LOCAL = 'local';
    case ONEC_PUSH = 'onec_push';

    public function label(): string
    {
        return match ($this) {
            self::LOCAL => 'Локальный режим',
            self::ONEC_PUSH => '1С (push)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode) => [$mode->value => $mode->label()])
            ->toArray();
    }
}
