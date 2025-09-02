<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool {
        // здесь проверяем права: менеджер этого кабинета или суперадмин
        // return $this->user()->can('create', \App\Models\DoctorShift::class);
        return true;
    }

    public function rules(): array {
        return [
            'doctor_id' => ['required','exists:users,id'],
            'cabinet_id' => ['required','exists:cabinets,id'],
            'date' => ['required','date'],
            'start_time' => ['required','date'],
            'end_time'   => ['required','date','after:start_time'],
            'slot_duration' => ['nullable','integer','min:5','max:240']
        ];
    }
}
