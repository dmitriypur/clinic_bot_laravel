<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сервис пакетного создания смен с автоматическим разбиением по дням и поддержкой перерывов.
 */
class MassShiftCreator
{
    public function __construct(
        protected ShiftService $shiftService
    ) {}

    /**
     * Создает одну или несколько смен в зависимости от диапазона и параметров перерыва.
     *
     * @param  array  $data  входные данные формы (doctor_id, cabinet_id, start_time, end_time, slot_duration, has_break, break_start_time, break_end_time, workday_start, workday_end, excluded_weekdays)
     * @return Collection созданные смены
     *
     * @throws ValidationException
     */
    public function createSeries(array $data): Collection
    {
        $timezone = config('app.timezone', 'UTC');

        $start = Carbon::parse($data['start_time'])->setTimezone($timezone);
        $end = Carbon::parse($data['end_time'])->setTimezone($timezone);

        $workdayStartTemplate = null;
        $workdayEndTemplate = null;

        if (! empty($data['workday_start'])) {
            $workdayStartTemplate = $this->parseTimeOnly($data['workday_start'], $start, 'workday_start', 'Неверный формат времени начала рабочего дня.');
        }

        if (! empty($data['workday_end'])) {
            $workdayEndTemplate = $this->parseTimeOnly($data['workday_end'], $end, 'workday_end', 'Неверный формат времени конца рабочего дня.');
        }

        if ($end->lte($start)) {
            throw ValidationException::withMessages([
                'end_time' => 'Время окончания должно быть позже времени начала.',
            ]);
        }

        $hasBreak = (bool) ($data['has_break'] ?? false);
        $breakBounds = $hasBreak ? $this->resolveBreakBounds($data, $start) : null;

        $defaultDailyStart = $workdayStartTemplate ?? $start->copy();
        $defaultDailyEnd = $workdayEndTemplate ?? $end->copy();

        $excludedWeekdays = collect($data['excluded_weekdays'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();

        return DB::transaction(function () use ($data, $start, $end, $breakBounds, $defaultDailyStart, $defaultDailyEnd, $workdayStartTemplate, $workdayEndTemplate, $excludedWeekdays) {
            $createdShifts = collect();
            $period = CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay());

            foreach ($period as $day) {
                if (! empty($excludedWeekdays) && in_array($day->isoWeekday(), $excludedWeekdays, true)) {
                    continue;
                }

                $segmentStart = $day->isSameDay($start)
                    ? $start->copy()
                    : $this->applyTemplateToDay($day, $workdayStartTemplate, $defaultDailyStart);

                $segmentEnd = $day->isSameDay($end)
                    ? $end->copy()
                    : $this->applyTemplateToDay($day, $workdayEndTemplate, $defaultDailyEnd);

                if ($segmentStart->gte($segmentEnd)) {
                    continue;
                }

                $dailyBreak = $this->resolveDailyBreak($segmentStart, $segmentEnd, $breakBounds);

                $segments = $this->buildSegments($segmentStart, $segmentEnd, $dailyBreak);

                if ($dailyBreak && empty($segments)) {
                    throw ValidationException::withMessages([
                        'break_start_time' => 'Перерыв покрывает смену полностью. Уберите перерыв или скорректируйте время.',
                    ]);
                }

                foreach ($segments as $window) {
                    try {
                        $createdShifts->push(
                            $this->shiftService->create([
                                'doctor_id' => $data['doctor_id'],
                                'cabinet_id' => $data['cabinet_id'],
                                'start_time' => $window['start'],
                                'end_time' => $window['end'],
                                'slot_duration' => $data['slot_duration'] ?? null,
                            ])
                        );
                    } catch (ValidationException $exception) {
                        $messages = $exception->errors();
                        $messages['shift_date'] = [
                            sprintf(
                                'Не удалось создать смену на %s. %s',
                                $segmentStart->format('d.m.Y'),
                                implode(' ', array_map(fn ($item) => is_array($item) ? implode(' ', $item) : $item, $messages))
                            ),
                        ];

                        throw ValidationException::withMessages($messages);
                    }
                }
            }

            return $createdShifts;
        });
    }

    /**
     * Преобразует входные данные перерыва в границы по времени.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface}
     *
     * @throws ValidationException
     */
    protected function resolveBreakBounds(array $data, CarbonInterface $base): array
    {
        $breakStartRaw = $data['break_start_time'] ?? null;
        $breakEndRaw = $data['break_end_time'] ?? null;

        if (! $breakStartRaw || ! $breakEndRaw) {
            throw ValidationException::withMessages([
                'break_start_time' => 'Укажите начало перерыва.',
                'break_end_time' => 'Укажите конец перерыва.',
            ]);
        }

        $breakStart = $this->parseTimeOnly($breakStartRaw, $base, 'break_start_time', 'Неверный формат времени перерыва.');
        $breakEnd = $this->parseTimeOnly($breakEndRaw, $base, 'break_end_time', 'Неверный формат времени перерыва.');

        if ($breakEnd->lte($breakStart)) {
            throw ValidationException::withMessages([
                'break_end_time' => 'Конец перерыва должен быть позже начала.',
            ]);
        }

        return [
            'start' => $breakStart,
            'end' => $breakEnd,
        ];
    }

    /**
     * Строит временные сегменты для создания смен.
     *
     * @return array<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    protected function buildSegments(CarbonInterface $segmentStart, CarbonInterface $segmentEnd, ?array $break): array
    {
        if (! $break) {
            return [['start' => $segmentStart, 'end' => $segmentEnd]];
        }

        $segments = [];

        if ($break['start']->gt($segmentStart)) {
            $segments[] = [
                'start' => $segmentStart->copy(),
                'end' => $break['start']->copy(),
            ];
        }

        if ($break['end']->lt($segmentEnd)) {
            $segments[] = [
                'start' => $break['end']->copy(),
                'end' => $segmentEnd->copy(),
            ];
        }

        return array_values(array_filter($segments, fn ($window) => $window['end']->gt($window['start'])));
    }

    /**
     * Возвращает перерыв, применимый для конкретного дня.
     */
    protected function resolveDailyBreak(CarbonInterface $segmentStart, CarbonInterface $segmentEnd, ?array $breakBounds): ?array
    {
        if (! $breakBounds) {
            return null;
        }

        $breakStart = $segmentStart->copy()->setTime(
            (int) $breakBounds['start']->format('H'),
            (int) $breakBounds['start']->format('i'),
            (int) $breakBounds['start']->format('s')
        );

        $breakEnd = $segmentStart->copy()->setTime(
            (int) $breakBounds['end']->format('H'),
            (int) $breakBounds['end']->format('i'),
            (int) $breakBounds['end']->format('s')
        );

        if ($breakEnd->lte($breakStart)) {
            return null;
        }

        if ($breakEnd->lte($segmentStart) || $breakStart->gte($segmentEnd)) {
            return null;
        }

        $breakStart = $breakStart->max($segmentStart);
        $breakEnd = $breakEnd->min($segmentEnd);

        if ($breakEnd->lte($breakStart)) {
            return null;
        }

        return [
            'start' => $breakStart,
            'end' => $breakEnd,
        ];
    }

    /**
     * Преобразует строку времени в Carbon с базовой датой.
     *
     *
     * @throws ValidationException
     */
    protected function parseTimeOnly(mixed $value, CarbonInterface $base, string $field, string $errorMessage = 'Неверный формат времени.'): Carbon
    {
        $timezone = $base->getTimezone();

        if ($value instanceof CarbonInterface) {
            return $base->copy()->setTime($value->hour, $value->minute, $value->second);
        }

        if ($value instanceof DateTimeInterface) {
            return $base->copy()->setTime((int) $value->format('H'), (int) $value->format('i'), (int) $value->format('s'));
        }

        if (is_string($value)) {
            try {
                $parsed = Carbon::parse($value, $timezone);

                return $base->copy()->setTime($parsed->hour, $parsed->minute, $parsed->second);
            } catch (\Throwable $exception) {
                // Продолжаем ниже и вернем сообщение об ошибке
            }

            $formats = ['H:i:s', 'H:i'];

            foreach ($formats as $format) {
                $parsed = Carbon::createFromFormat($format, $value, $timezone);

                if ($parsed !== false) {
                    return $base->copy()->setTime($parsed->hour, $parsed->minute, $parsed->second);
                }
            }
        }

        throw ValidationException::withMessages([
            $field => $errorMessage,
        ]);
    }

    /**
     * Применяет временной шаблон к конкретному дню.
     */
    protected function applyTemplateToDay(CarbonInterface $day, ?CarbonInterface $template, CarbonInterface $fallback): CarbonInterface
    {
        $reference = $template ?? $fallback;

        return $day->copy()->setTime(
            $reference->hour,
            $reference->minute,
            $reference->second
        );
    }
}
