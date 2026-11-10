<?php

namespace App\Http\Requests\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AvailabilitySearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'city_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'clinic_ids' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:20'],
            'clinic_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'branch_ids' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:20'],
            'branch_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'doctor_ids' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:50'],
            'doctor_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'specialty' => ['sometimes', 'required', 'string', 'max:120', 'regex:/[\p{L}\p{N}]/u'],
            'date_from' => ['required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'time_from' => ['sometimes', 'required', 'date_format:H:i'],
            'time_to' => ['sometimes', 'required', 'date_format:H:i'],
            'patient_age' => ['sometimes', 'required', 'integer', 'between:0,130'],
            'birth_date' => ['sometimes', 'required', 'prohibits:patient_age', 'date_format:Y-m-d', 'before_or_equal:today'],
            'limit' => ['sometimes', 'required', 'integer', 'between:1,50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff_key($this->all(), $this->rules()) as $field => $value) {
                $validator->errors()->add($field, 'Параметр не поддерживается.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->validated('date_from');
            $to = $this->validated('date_to');
            if ($to && $to > CarbonImmutable::parse($from, 'UTC')->addDays(30)->toDateString()) {
                $validator->errors()->add('date_to', 'Период поиска не может превышать 31 день.');
            }

            if ($this->validated('time_from', '00:00') > $this->validated('time_to', '23:59')) {
                $validator->errors()->add('time_to', 'Конец диапазона времени должен быть не раньше начала.');
            }
        }];
    }
}
