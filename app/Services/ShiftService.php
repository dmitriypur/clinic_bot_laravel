<?php
namespace App\Services;

use App\Models\DoctorShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class ShiftService
{
    /**
     * Создать смену с проверкой пересечений.
     * Бросает ValidationException при конфликте.
     */
    public function create(array $data): DoctorShift
    {
        return DB::transaction(function() use ($data) {
            $starts = Carbon::parse($data['start_time'])->setTimezone('UTC');
            $ends   = Carbon::parse($data['end_time'])->setTimezone('UTC');

            if ($ends->lte($starts)) {
                throw ValidationException::withMessages(['end_time' => 'Время конца должно быть позже начала']);
            }

            // 1) Проверка: врач не должен иметь пересечения в другом кабинете
            $conflictDoctor = DoctorShift::where('doctor_id', $data['doctor_id'])
                ->where(function($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])
                      ->orWhereBetween('end_time', [$starts, $ends])
                      ->orWhere(function($qq) use ($starts, $ends) {
                          $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                      });
                })
                ->exists();

            if ($conflictDoctor) {
                throw ValidationException::withMessages(['doctor_id' => 'У врача есть пересечение в другом кабинете/время занято']);
            }

            // 2) Проверка: в одном кабинете не должно быть двух врачей одновременно (по желанию)
            $conflictCabinet = DoctorShift::where('cabinet_id', $data['cabinet_id'])
                ->where(function($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])
                      ->orWhereBetween('end_time', [$starts, $ends])
                      ->orWhere(function($qq) use ($starts, $ends) {
                          $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                      });
                })
                ->exists();

            if ($conflictCabinet) {
                throw ValidationException::withMessages(['cabinet_id' => 'В этом кабинете уже назначен врач в заданное время']);
            }

            // Создаём
            return DoctorShift::create([
                'cabinet_id' => $data['cabinet_id'],
                'doctor_id'  => $data['doctor_id'],
                'start_time'  => $starts,
                'end_time'    => $ends,
                'slot_duration' => $data['slot_duration'] ?? 30,
            ]);
        });
    }

    /**
     * Обновление смены с проверками (аналогично create).
     */
    public function update(DoctorShift $shift, array $data): DoctorShift
    {
        return DB::transaction(function() use ($shift, $data) {
            $starts = Carbon::parse($data['start_time'])->setTimezone('UTC');
            $ends   = Carbon::parse($data['end_time'])->setTimezone('UTC');

            if ($ends->lte($starts)) {
                throw ValidationException::withMessages(['end_time' => 'Время конца должно быть позже начала']);
            }

            // Проверки как в create, но исключаем текущую смену по id
            $conflictDoctor = DoctorShift::where('doctor_id', $data['doctor_id'])
                ->where('id', '!=', $shift->id)
                ->where(function($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])
                      ->orWhereBetween('end_time', [$starts, $ends])
                      ->orWhere(function($qq) use ($starts, $ends) {
                          $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                      });
                })
                ->exists();

            if ($conflictDoctor) {
                throw ValidationException::withMessages(['doctor_id' => 'У врача есть пересечение в другом кабинете/время занято']);
            }

            $conflictCabinet = DoctorShift::where('cabinet_id', $data['cabinet_id'])
                ->where('id', '!=', $shift->id)
                ->where(function($q) use ($starts, $ends) {
                    $q->whereBetween('start_time', [$starts, $ends])
                      ->orWhereBetween('end_time', [$starts, $ends])
                      ->orWhere(function($qq) use ($starts, $ends) {
                          $qq->where('start_time', '<=', $starts)->where('end_time', '>=', $ends);
                      });
                })
                ->exists();

            if ($conflictCabinet) {
                throw ValidationException::withMessages(['cabinet_id' => 'В этом кабинете уже назначен врач в заданное время']);
            }

            $shift->update([
                'cabinet_id' => $data['cabinet_id'],
                'doctor_id'  => $data['doctor_id'],
                'start_time'  => $starts,
                'end_time'    => $ends,
                'slot_duration' => $data['slot_duration'] ?? $shift->slot_duration,
            ]);

            return $shift->refresh();
        });
    }

    public function delete(DoctorShift $shift): void
    {
        $shift->delete();
    }
}
