<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cabinet;
use App\Models\DoctorShift;
use App\Services\ShiftService;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;

class CabinetShiftController extends Controller
{
    public function events(Request $request, Cabinet $cabinet)
    {
        // проверка доступа: менеджер может видеть только свои кабинеты — добавь авторизацию если нужно
        $from = $request->query('start');
        $to   = $request->query('end');

        $shifts = DoctorShift::where('cabinet_id', $cabinet->id)
            ->whereBetween('start_time', [ $from, $to ])
            ->get();

        // Преобразуем в формат FullCalendar
        $events = $shifts->map(function($s) {
            return [
                'id' => $s->id,
                'start' => $s->start_time->toIso8601String(),
                'end'   => $s->end_time->toIso8601String(),
                'title' => $s->doctor->name ?? '—',
                'extendedProps' => [
                    'doctor_id' => $s->doctor_id,
                    'cabinet_id' => $s->cabinet_id,
                    'slot_duration' => $s->slot_duration,
                ],
                // можем пометить как background, если нужно
            ];
        });

        return response()->json($events);
    }

    public function store(StoreShiftRequest $req, Cabinet $cabinet, ShiftService $service)
    {
        $data = $req->validated();
        $data['cabinet_id'] = $cabinet->id;

        $shift = $service->create($data);

        return response()->json([
            'id' => $shift->id,
            'start' => $shift->start_time->toIso8601String(),
            'end'   => $shift->end_time->toIso8601String(),
            'title' => $shift->doctor->name,
        ], 201);
    }

    public function update(UpdateShiftRequest $req, Cabinet $cabinet, DoctorShift $shift, ShiftService $service)
    {
        // убедиться, что shift.cabinet_id == cabinet.id или авторизовать
        if ($shift->cabinet_id !== $cabinet->id) {
            return response()->json(['message'=>'Shift не в этом кабинете'], 403);
        }
        $data = $req->validated();
        $data['cabinet_id'] = $cabinet->id;

        $updated = $service->update($shift, $data);

        return response()->json([
            'id' => $updated->id,
            'start' => $updated->start_time->toIso8601String(),
            'end'   => $updated->end_time->toIso8601String(),
            'title' => $updated->doctor->name,
        ]);
    }

    public function destroy(Cabinet $cabinet, DoctorShift $shift, ShiftService $service)
    {
        if ($shift->cabinet_id !== $cabinet->id) {
            return response()->json(['message'=>'Shift не в этом кабинете'], 403);
        }
        $service->delete($shift);
        return response()->json([], 204);
    }
}
