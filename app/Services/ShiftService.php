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
            [$starts, $ends] = $this->normalizeInterval($data);

            $this->purgeSoftDeletedDuplicates($data['doctor_id'], $data['cabinet_id'], $starts, $ends);
            $this->assertNoConflicts($data['doctor_id'], $data['cabinet_id'], $starts, $ends);

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
            [$starts, $ends] = $this->normalizeInterval($data);

            $this->purgeSoftDeletedDuplicates($data['doctor_id'], $data['cabinet_id'], $starts, $ends);
            $this->assertNoConflicts($data['doctor_id'], $data['cabinet_id'], $starts, $ends, $shift->id);

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

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function normalizeInterval(array $data): array
    {
        $starts = Carbon::parse($data['start_time'])->setTimezone('UTC');
        $ends = Carbon::parse($data['end_time'])->setTimezone('UTC');

        if ($ends->lte($starts)) {
            throw ValidationException::withMessages(['end_time' => 'Время конца должно быть позже начала']);
        }

        return [$starts, $ends];
    }

    protected function purgeSoftDeletedDuplicates(int $doctorId, int $cabinetId, Carbon $starts, Carbon $ends): void
    {
        DoctorShift::withTrashed()
            ->where('doctor_id', $doctorId)
            ->where('cabinet_id', $cabinetId)
            ->where('start_time', $starts)
            ->where('end_time', $ends)
            ->whereNotNull('deleted_at')
            ->get()
            ->each->forceDelete();
    }

    protected function assertNoConflicts(int $doctorId, int $cabinetId, Carbon $starts, Carbon $ends, ?int $ignoredShiftId = null): void
    {
        if ($this->hasOverlap('doctor_id', $doctorId, $starts, $ends, $ignoredShiftId)) {
            throw ValidationException::withMessages(['doctor_id' => 'У врача есть пересечение в другом кабинете/время занято']);
        }

        if ($this->hasOverlap('cabinet_id', $cabinetId, $starts, $ends, $ignoredShiftId)) {
            throw ValidationException::withMessages(['cabinet_id' => 'В этом кабинете уже назначен врач в заданное время']);
        }
    }

    protected function hasOverlap(string $column, int $value, Carbon $starts, Carbon $ends, ?int $ignoredShiftId = null): bool
    {
        return DoctorShift::query()
            ->where($column, $value)
            ->when($ignoredShiftId !== null, fn ($query) => $query->where('id', '!=', $ignoredShiftId))
            ->between($starts, $ends)
            ->exists();
    }
}
