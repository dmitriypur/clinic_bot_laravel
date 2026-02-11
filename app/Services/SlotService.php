<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\DoctorShift;
use Carbon\Carbon;

/**
 * Сервис для работы со слотами времени
 *
 * Обеспечивает единообразную работу с временными слотами для записи пациентов
 * на разных уровнях: смена врача, филиал, клиника
 */
class SlotService
{
    /**
     * Получить длительность слота для филиала
     *
     * @return int Длительность в минутах
     */
    public function getBranchSlotDuration(Branch $branch): int
    {
        return $branch->getEffectiveSlotDuration();
    }

    /**
     * Получить длительность слота для клиники
     *
     * @return int Длительность в минутах
     */
    public function getClinicSlotDuration(Clinic $clinic): int
    {
        return $clinic->getEffectiveSlotDuration();
    }

    /**
     * Получить длительность слота для смены врача
     *
     * @return int Длительность в минутах
     */
    public function getShiftSlotDuration(DoctorShift $shift): int
    {
        return $shift->getEffectiveSlotDuration();
    }

    /**
     * Разбить временной диапазон на слоты
     *
     * @param  Carbon  $startTime  Время начала
     * @param  Carbon  $endTime  Время окончания
     * @param  int  $slotDuration  Длительность слота в минутах
     * @return array Массив слотов
     */
    public function generateTimeSlots(Carbon $startTime, Carbon $endTime, int $slotDuration): array
    {
        $slots = [];
        $current = $startTime->copy();

        while ($current->addMinutes($slotDuration)->lte($endTime)) {
            $slotStart = $current->copy()->subMinutes($slotDuration);
            $slotEnd = $current->copy();

            $slots[] = [
                'start' => $slotStart,
                'end' => $slotEnd,
                'duration' => $slotDuration,
                'formatted' => $slotStart->format('H:i').' - '.$slotEnd->format('H:i'),
                'start_formatted' => $slotStart->format('H:i'),
                'end_formatted' => $slotEnd->format('H:i'),
            ];
        }

        return $slots;
    }

    /**
     * Получить слоты для смены врача
     *
     * @return array Массив слотов
     */
    public function getShiftTimeSlots(DoctorShift $shift): array
    {
        return $shift->getTimeSlots();
    }

    /**
     * Проверить, доступен ли слот времени
     *
     * @param  Carbon  $startTime  Время начала слота
     * @param  Carbon  $endTime  Время окончания слота
     * @param  DoctorShift  $shift  Смена врача
     */
    public function isSlotAvailable(Carbon $startTime, Carbon $endTime, DoctorShift $shift): bool
    {
        // Проверяем, что слот находится в пределах смены
        if ($startTime->lt($shift->start_time) || $endTime->gt($shift->end_time)) {
            return false;
        }

        // Проверяем, что длительность слота соответствует настройкам
        $slotDuration = $endTime->diffInMinutes($startTime);
        $expectedDuration = $this->getShiftSlotDuration($shift);

        if ($slotDuration !== $expectedDuration) {
            return false;
        }

        // TODO: Здесь можно добавить проверку на занятость слота
        // (например, проверить, нет ли уже записей на это время)

        return true;
    }

    /**
     * Получить доступные слоты для филиала на определенную дату
     *
     * @return array Массив доступных слотов
     */
    public function getAvailableSlotsForBranch(Branch $branch, Carbon $date): array
    {
        $slots = [];
        $slotDuration = $this->getBranchSlotDuration($branch);

        // Получаем все смены врачей в филиале на указанную дату
        $shifts = DoctorShift::whereHas('cabinet', function ($query) use ($branch) {
            $query->where('branch_id', $branch->id);
        })
            ->whereDate('start_time', $date)
            ->with(['doctor', 'cabinet'])
            ->get();

        foreach ($shifts as $shift) {
            $shiftSlots = $this->getShiftTimeSlots($shift);

            foreach ($shiftSlots as $slot) {
                if ($this->isSlotAvailable($slot['start'], $slot['end'], $shift)) {
                    $slots[] = array_merge($slot, [
                        'shift_id' => $shift->id,
                        'doctor' => $shift->doctor,
                        'cabinet' => $shift->cabinet,
                    ]);
                }
            }
        }

        return $slots;
    }

    /**
     * Получить стандартные варианты длительности слотов
     */
    public function getStandardSlotDurations(): array
    {
        return [
            15 => '15 минут',
            30 => '30 минут',
            45 => '45 минут',
            60 => '1 час',
            90 => '1.5 часа',
            120 => '2 часа',
        ];
    }
}
