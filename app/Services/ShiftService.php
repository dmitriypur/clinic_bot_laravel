<?php

namespace App\Services;

use App\Models\DoctorShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сервис для управления сменами врачей
 *
 * Предоставляет бизнес-логику для создания, обновления и удаления смен врачей.
 * Включает проверки на пересечения времени и конфликты расписания.
 * Использует транзакции для обеспечения целостности данных.
 */
class ShiftService
{
    /**
     * Создание новой смены врача с проверкой пересечений
     * Выполняет проверки на конфликты времени и создает смену в транзакции
     *
     * @param  array  $data  Данные смены (doctor_id, cabinet_id, start_time, end_time, slot_duration)
     * @return DoctorShift Созданная смена
     *
     * @throws ValidationException При обнаружении конфликтов времени
     */
    public function create(array $data): DoctorShift
    {
        return DB::transaction(function () use ($data) {
            // Парсим и нормализуем время в UTC
            $starts = Carbon::parse($data['start_time'])->setTimezone('UTC');
            $ends = Carbon::parse($data['end_time'])->setTimezone('UTC');

            // Проверяем, что время окончания позже времени начала
            if ($ends->lte($starts)) {
                throw ValidationException::withMessages(['end_time' => 'Время конца должно быть позже начала']);
            }

            // Удаляем мягко удаленные дубликаты, если они мешают уникальному индексу
            DoctorShift::withTrashed()
                ->where('doctor_id', $data['doctor_id'])
                ->where('cabinet_id', $data['cabinet_id'])
                ->where('start_time', $starts)
                ->where('end_time', $ends)
                ->whereNotNull('deleted_at')
                ->get()
                ->each->forceDelete();

            // 1) Проверка: врач не должен иметь пересечения в любом кабинете
            $conflictDoctor = DoctorShift::where('doctor_id', $data['doctor_id'])
                ->where(function ($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])  // Смена начинается в диапазоне
                        ->orWhereBetween('end_time', [$starts, $ends])  // Смена заканчивается в диапазоне
                        ->orWhere(function ($qq) use ($starts, $ends) {  // Смена полностью покрывает диапазон
                            $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                        });
                })
                ->exists();

            if ($conflictDoctor) {
                throw ValidationException::withMessages(['doctor_id' => 'У врача есть пересечение в другом кабинете/время занято']);
            }

            // 2) Проверка: в одном кабинете не должно быть двух врачей одновременно
            $conflictCabinet = DoctorShift::where('cabinet_id', $data['cabinet_id'])
                ->where(function ($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])  // Смена начинается в диапазоне
                        ->orWhereBetween('end_time', [$starts, $ends])  // Смена заканчивается в диапазоне
                        ->orWhere(function ($qq) use ($starts, $ends) {  // Смена полностью покрывает диапазон
                            $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                        });
                })
                ->exists();

            if ($conflictCabinet) {
                throw ValidationException::withMessages(['cabinet_id' => 'В этом кабинете уже назначен врач в заданное время']);
            }

            // Создаем смену после успешных проверок
            return DoctorShift::create([
                'cabinet_id' => $data['cabinet_id'],
                'doctor_id' => $data['doctor_id'],
                'start_time' => $starts,
                'end_time' => $ends,
                'slot_duration' => $data['slot_duration'] ?? 30,  // По умолчанию 30 минут
            ]);
        });
    }

    /**
     * Обновление существующей смены с проверкой пересечений
     * Выполняет те же проверки, что и create, но исключает текущую смену из проверок
     *
     * @param  DoctorShift  $shift  Смена для обновления
     * @param  array  $data  Новые данные смены
     * @return DoctorShift Обновленная смена
     *
     * @throws ValidationException При обнаружении конфликтов времени
     */
    public function update(DoctorShift $shift, array $data): DoctorShift
    {
        return DB::transaction(function () use ($shift, $data) {
            // Парсим и нормализуем время в UTC
            $starts = Carbon::parse($data['start_time'])->setTimezone('UTC');
            $ends = Carbon::parse($data['end_time'])->setTimezone('UTC');

            // Проверяем, что время окончания позже времени начала
            if ($ends->lte($starts)) {
                throw ValidationException::withMessages(['end_time' => 'Время конца должно быть позже начала']);
            }

            DoctorShift::withTrashed()
                ->where('doctor_id', $data['doctor_id'])
                ->where('cabinet_id', $data['cabinet_id'])
                ->where('start_time', $starts)
                ->where('end_time', $ends)
                ->whereNotNull('deleted_at')
                ->get()
                ->each->forceDelete();

            // 1) Проверка: врач не должен иметь пересечения в любом кабинете (исключая текущую смену)
            $conflictDoctor = DoctorShift::where('doctor_id', $data['doctor_id'])
                ->where('id', '!=', $shift->id)  // Исключаем текущую смену
                ->where(function ($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])  // Смена начинается в диапазоне
                        ->orWhereBetween('end_time', [$starts, $ends])  // Смена заканчивается в диапазоне
                        ->orWhere(function ($qq) use ($starts, $ends) {  // Смена полностью покрывает диапазон
                            $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                        });
                })
                ->exists();

            if ($conflictDoctor) {
                throw ValidationException::withMessages(['doctor_id' => 'У врача есть пересечение в другом кабинете/время занято']);
            }

            // 2) Проверка: в одном кабинете не должно быть двух врачей одновременно (исключая текущую смену)
            $conflictCabinet = DoctorShift::where('cabinet_id', $data['cabinet_id'])
                ->where('id', '!=', $shift->id)  // Исключаем текущую смену
                ->where(function ($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])  // Смена начинается в диапазоне
                        ->orWhereBetween('end_time', [$starts, $ends])  // Смена заканчивается в диапазоне
                        ->orWhere(function ($qq) use ($starts, $ends) {  // Смена полностью покрывает диапазон
                            $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                        });
                })
                ->exists();

            if ($conflictCabinet) {
                throw ValidationException::withMessages(['cabinet_id' => 'В этом кабинете уже назначен врач в заданное время']);
            }

            // Обновляем смену после успешных проверок
            $shift->update([
                'cabinet_id' => $data['cabinet_id'],
                'doctor_id' => $data['doctor_id'],
                'start_time' => $starts,
                'end_time' => $ends,
                'slot_duration' => $data['slot_duration'] ?? $shift->slot_duration,  // Сохраняем текущее значение если не указано
            ]);

            return $shift->refresh();
        });
    }

    /**
     * Удаление смены врача
     * Выполняет мягкое удаление (soft delete) для сохранения истории
     *
     * @param  DoctorShift  $shift  Смена для удаления
     */
    public function delete(DoctorShift $shift): void
    {
        $shift->delete();
    }
}
