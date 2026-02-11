<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendAppointmentConfirmationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $applicationId) {}

    public function getApplicationId(): int
    {
        return $this->applicationId;
    }

    public function handle(TelegramService $telegram): void
    {
        $application = Application::with(['clinic', 'branch', 'doctor', 'cabinet'])
            ->find($this->applicationId);

        if (! $application) {
            Log::warning('Application not found while sending Telegram confirmation.', [
                'application_id' => $this->applicationId,
            ]);

            return;
        }

        if (! $application->tg_chat_id) {
            return;
        }

        $message = $this->buildMessage($application);

        if ($message === null) {
            Log::info('Skipping Telegram confirmation due to empty message.', [
                'application_id' => $this->applicationId,
            ]);

            return;
        }

        $telegram->sendMessage($application->tg_chat_id, $message);
    }

    private function buildMessage(Application $application): ?string
    {
        $lines = ['Ваша заявка подтверждена!'];

        $datetime = $application->appointment_datetime;

        if ($datetime) {
            $lines[] = '';
            $lines[] = 'Дата: '.$datetime->format('d.m.Y');
            $lines[] = 'Время: '.$datetime->format('H:i');
        }

        $doctorName = trim(
            (string) ($application->doctor?->full_name ?? $application->doctor?->name ?? '')
        );

        if ($doctorName !== '') {
            $lines[] = 'Врач: '.$doctorName;
        }

        $clinicName = $application->clinic?->name;
        if ($clinicName) {
            $lines[] = 'Клиника: '.$clinicName;
        }

        $branchName = $application->branch?->name;
        if ($branchName) {
            $lines[] = 'Филиал: '.$branchName;
        }

        $branchAddress = $application->branch?->address;
        if ($branchAddress) {
            $lines[] = 'Адрес: '.$branchAddress;
        }

        $cabinetName = $application->cabinet?->name;
        if ($cabinetName) {
            $lines[] = 'Кабинет: '.$cabinetName;
        }

        $lines = array_filter($lines, static fn ($line) => Str::of($line)->trim()->isNotEmpty());

        if (empty($lines)) {
            return null;
        }

        return implode("\n", $lines);
    }
}
