<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class OneCScheduleWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // TEMP: сохраняем сырой payload от 1С для диагностики недостающих филиалов/врачей.
        file_put_contents(
            storage_path('logs/onec-incoming-raw.log'),
            now()->toDateTimeString().' '.$this->getContent().PHP_EOL
        );

        $payload = $this->all();

        if (! isset($payload['slots']) && is_array($payload) && array_is_list($payload)) {
            $this->merge([
                'slots' => $payload,
            ]);
        }

        if (! isset($payload['schedule']) && $this->looksLikeLegacySchedule($payload)) {
            $this->merge([
                'schedule' => $payload,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'branch_external_id' => ['required_without:schedule', 'string'],
            'slots' => ['required_without:schedule', 'array', 'min:1'],
            'slots.*.slot_id' => ['required', 'string'],
            'slots.*.start_at' => ['required', 'date'],
            'slots.*.end_at' => ['required', 'date'],
            'slots.*.status' => ['required', 'string'],
            'slots.*.doctor_id' => ['nullable'],
            'slots.*.doctor.external_id' => ['nullable', 'string'],
            'slots.*.cabinet_id' => ['nullable'],
            'slots.*.cabinet.external_id' => ['nullable', 'string'],
            'schedule' => ['required_without:slots', 'array'],
            'schedule.data' => ['required_with:schedule', 'array'],
        ];
    }

    public function slots(): array
    {
        return Arr::get($this->validated(), 'slots', []);
    }

    public function hasStructuredSlots(): bool
    {
        return ! empty($this->slots());
    }

    public function legacySchedule(): array
    {
        return Arr::get($this->validated(), 'schedule', []);
    }

    /**
     * @param  array<mixed>  $payload
     */
    protected function looksLikeLegacySchedule(array $payload): bool
    {
        if (isset($payload['schedule']['data'])) {
            return true;
        }

        foreach ($payload as $item) {
            if (is_array($item) && isset($item['schedule']['data'])) {
                return true;
            }
        }

        return false;
    }
}
