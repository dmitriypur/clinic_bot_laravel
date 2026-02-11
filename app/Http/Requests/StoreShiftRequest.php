<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Запрос валидации для создания смены врача
 *
 * Проверяет данные при создании новой смены врача.
 * Включает валидацию существования врача и кабинета, корректности времени и длительности слота.
 */
class StoreShiftRequest extends FormRequest
{
    /**
     * Проверка авторизации для создания смены
     * TODO: Реализовать проверку прав доступа
     */
    public function authorize(): bool
    {
        // TODO: Проверить права: менеджер этого кабинета или суперадмин
        // return $this->user()->can('create', \App\Models\DoctorShift::class);
        return true;
    }

    /**
     * Правила валидации для создания смены
     */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'exists:users,id'],  // Врач должен существовать
            'cabinet_id' => ['required', 'exists:cabinets,id'],  // Кабинет должен существовать
            'date' => ['required', 'date'],  // Дата смены (позже будет удалено)
            'start_time' => ['required', 'date'],  // Время начала смены
            'end_time' => ['required', 'date', 'after:start_time'],  // Время окончания должно быть после начала
            'slot_duration' => ['nullable', 'integer', 'min:5', 'max:240'],  // Длительность слота 5-240 минут
        ];
    }
}
