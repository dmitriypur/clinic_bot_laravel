<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\CrmIntegrationLog;
use App\Services\Crm\CrmNotifierFactory;
use App\Services\Crm\CrmNotificationResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCrmNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $applicationId)
    {
    }

    public function handle(CrmNotifierFactory $factory): void
    {
        $application = Application::with(['clinic', 'branch', 'doctor'])->find($this->applicationId);

        if (!$application) {
            return;
        }

        $clinic = $application->clinic;

        if (!$clinic || $clinic->crm_provider === 'none' || !$clinic->crm_provider) {
            return;
        }

        $settings = $clinic->crm_settings ?? [];

        $notifier = $factory->make($clinic->crm_provider);

        if (!$notifier) {
            Log::warning('CRM notifier not found for provider.', [
                'clinic_id' => $clinic->id,
                'provider' => $clinic->crm_provider,
            ]);
            return;
        }

        $result = $notifier->send($application, $settings);

        $this->logAttempt($application, $result);

        if (!$result->success && $this->attempts() < 3) {
            $this->release(60);
        }
    }

    protected function logAttempt(Application $application, CrmNotificationResult $result): void
    {
        CrmIntegrationLog::create([
            'clinic_id' => $application->clinic_id,
            'application_id' => $application->id,
            'provider' => $application->clinic?->crm_provider ?? 'unknown',
            'status' => $result->success ? 'success' : 'error',
            'payload' => $application->clinic?->crm_settings,
            'response' => $result->response,
            'error_message' => $result->error,
            'attempt' => $this->attempts(),
        ]);
    }
}
