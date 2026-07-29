<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class VerifyBookingMonitoringConfig extends Command
{
    protected $signature = 'booking:verify-monitoring-config';

    protected $description = 'Проверить серверные настройки мониторинга записи без вывода секретов';

    public function handle(): int
    {
        if (! config('booking-monitoring.enabled')) {
            $this->components->warn('Мониторинг отключён. Деплой продолжится без проверок 1С и Bitrix24.');

            return self::SUCCESS;
        }

        $errors = [];

        $url = (string) config('booking-monitoring.url');
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'BOOKING_MONITORING_URL не заполнен или имеет неверный формат.';
        }

        $runTimes = config('booking-monitoring.run_times', []);
        if (! is_array($runTimes) || count($runTimes) !== 3) {
            $errors[] = 'BOOKING_MONITORING_RUN_TIMES должен содержать три времени запуска.';
        } else {
            foreach ($runTimes as $runTime) {
                if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $runTime)) {
                    $errors[] = 'В BOOKING_MONITORING_RUN_TIMES найдено время неверного формата.';
                    break;
                }
            }
        }

        $webhookUrl = (string) config('booking-monitoring.bitrix.webhook_url');
        $dialogId = (string) config('booking-monitoring.bitrix.dialog_id');

        if (! filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Webhook Bitrix24 не заполнен или имеет неверный формат.';
        } elseif (! preg_match('~^/rest/\d+/[^/]+/?$~', (string) parse_url($webhookUrl, PHP_URL_PATH))) {
            $errors[] = 'Webhook Bitrix24 должен быть базовым адресом без im.message.add.json.';
        }

        if (! preg_match('/^(?:(?:chat|sg)\d+|\d+)$/', $dialogId)) {
            $errors[] = 'Идентификатор чата Bitrix24 не заполнен или имеет неверный формат.';
        }

        if (in_array(config('queue.default'), [null, '', 'sync'], true)) {
            $errors[] = 'QUEUE_CONNECTION должен использовать фоновую очередь, например database или redis.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info('Настройки мониторинга корректны. Секреты в лог не выводились.');

        return self::SUCCESS;
    }
}
