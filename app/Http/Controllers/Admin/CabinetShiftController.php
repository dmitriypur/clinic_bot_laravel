<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Models\Cabinet;
use App\Models\DoctorShift;
use App\Services\ShiftService;
use Illuminate\Http\Request;

/**
 * Контроллер для управления сменами врачей в кабинетах
 *
 * Предоставляет API endpoints для работы с расписанием врачей в кабинетах.
 * Включает методы для получения, создания, обновления и удаления смен.
 * Использует ShiftService для бизнес-логики работы со сменами.
 */
class CabinetShiftController extends Controller
{
    /**
     * Получение событий (смен) для календаря
     * Возвращает смены врачей для конкретного кабинета в указанном диапазоне дат
     */
    public function events(Request $request, Cabinet $cabinet)
    {
        // TODO: Добавить проверку доступа - менеджер может видеть только свои кабинеты
        $from = $request->query('start');  // Начальная дата диапазона
        $to = $request->query('end');    // Конечная дата диапазона

        // Получаем смены для кабинета в указанном диапазоне дат
        $shifts = DoctorShift::where('cabinet_id', $cabinet->id)
            ->whereBetween('start_time', [$from, $to])
            ->get();

        // Преобразуем в формат FullCalendar
        $events = $shifts->map(function ($s) {
            return [
                'id' => $s->id,
                'start' => $s->start_time->toIso8601String(),
                'end' => $s->end_time->toIso8601String(),
                'title' => $s->doctor->name ?? '—',
                'extendedProps' => [
                    'doctor_id' => $s->doctor_id,
                    'cabinet_id' => $s->cabinet_id,
                    'slot_duration' => $s->slot_duration,
                ],
                // TODO: Можно пометить как background, если нужно
            ];
        });

        return response()->json($events);
    }

    /**
     * Создание новой смены врача
     * Создает смену для указанного кабинета
     */
    public function store(StoreShiftRequest $req, Cabinet $cabinet, ShiftService $service)
    {
        $data = $req->validated();
        $data['cabinet_id'] = $cabinet->id;  // Привязываем смену к кабинету

        $shift = $service->create($data);

        return response()->json([
            'id' => $shift->id,
            'start' => $shift->start_time->toIso8601String(),
            'end' => $shift->end_time->toIso8601String(),
            'title' => $shift->doctor->name,
        ], 201);
    }

    /**
     * Обновление существующей смены врача
     * Обновляет смену с проверкой принадлежности к кабинету
     */
    public function update(UpdateShiftRequest $req, Cabinet $cabinet, DoctorShift $shift, ShiftService $service)
    {
        // Проверяем, что смена принадлежит указанному кабинету
        if ($shift->cabinet_id !== $cabinet->id) {
            return response()->json(['message' => 'Shift не в этом кабинете'], 403);
        }

        $data = $req->validated();
        $data['cabinet_id'] = $cabinet->id;

        $updated = $service->update($shift, $data);

        return response()->json([
            'id' => $updated->id,
            'start' => $updated->start_time->toIso8601String(),
            'end' => $updated->end_time->toIso8601String(),
            'title' => $updated->doctor->name,
        ]);
    }

    /**
     * Удаление смены врача
     * Удаляет смену с проверкой принадлежности к кабинету
     */
    public function destroy(Cabinet $cabinet, DoctorShift $shift, ShiftService $service)
    {
        // Проверяем, что смена принадлежит указанному кабинету
        if ($shift->cabinet_id !== $cabinet->id) {
            return response()->json(['message' => 'Shift не в этом кабинете'], 403);
        }

        $service->delete($shift);

        return response()->json([], 204);
    }
}
