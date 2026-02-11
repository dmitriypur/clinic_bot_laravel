<?php

declare(strict_types=1);

namespace App\Services\OneC;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OneCLegacyScheduleTransformer
{
    /**
     * Преобразует legacy-структуру (как в docs/data-ext.md) к виду [branch_id => slots[]].
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function transform(array $payload): array
    {
        $schedules = $this->extractSchedules($payload);

        $result = [];

        foreach ($schedules as $schedule) {
            $data = Arr::get($schedule, 'data', []);

            foreach ($data as $branchExternalId => $branchData) {
                if (! is_array($branchData)) {
                    continue;
                }

                foreach ($branchData as $doctorExternalId => $doctorData) {
                    if (! is_array($doctorData)) {
                        continue;
                    }

                    $cells = Arr::get($doctorData, 'cells', []);

                    foreach ($cells as $cell) {
                        $slot = $this->makeSlotPayload(
                            branchExternalId: (string) $branchExternalId,
                            doctorExternalId: (string) $doctorExternalId,
                            cell: (array) $cell,
                            doctorMeta: $doctorData
                        );

                        if ($slot !== null) {
                            $result[$branchExternalId][] = $slot;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    protected function extractSchedules(array $payload): array
    {
        if (isset($payload['schedule'])) {
            return [Arr::get($payload, 'schedule', [])];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return [$payload];
        }

        $schedules = [];

        foreach ($payload as $item) {
            if (is_array($item) && isset($item['schedule'])) {
                $schedules[] = $item['schedule'];

                continue;
            }

            if (is_array($item) && isset($item['data'])) {
                $schedules[] = $item;
            }
        }

        return $schedules;
    }

    /**
     * @param  array<string,mixed>  $doctorMeta
     */
    protected function makeSlotPayload(string $branchExternalId, string $doctorExternalId, array $cell, array $doctorMeta): ?array
    {
        $date = Arr::get($cell, 'dt');
        $timeStart = Arr::get($cell, 'time_start');
        $timeEnd = Arr::get($cell, 'time_end');

        if (! $date || ! $timeStart || ! $timeEnd) {
            return null;
        }

        $timezone = config('app.timezone', 'UTC');

        try {
            $startAt = Carbon::parse(sprintf('%s %s', $date, $timeStart), $timezone);
            $endAt = Carbon::parse(sprintf('%s %s', $date, $timeEnd), $timezone);
        } catch (\Throwable) {
            return null;
        }

        $slotId = Arr::get($cell, 'slot_id')
            ?? sprintf(
                '%s-%s-%s-%s',
                $branchExternalId,
                $doctorExternalId,
                Str::of($date)->replace('-', ''),
                Str::of($timeStart)->replace(':', '')
            );

        $isFree = Arr::get($cell, 'free', true);
        $status = $isFree ? 'free' : 'booked';

        return [
            'slot_id' => (string) $slotId,
            'branch_id' => $branchExternalId,
            'doctor_id' => $doctorExternalId,
            'start_at' => $startAt->toIso8601String(),
            'end_at' => $endAt->toIso8601String(),
            'status' => $status,
            'doctor' => [
                'external_id' => $doctorExternalId,
                'efio' => Arr::get($doctorMeta, 'efio'),
                'espec' => Arr::get($doctorMeta, 'espec'),
            ],
            'meta' => [
                'raw_cell' => $cell,
            ],
        ];
    }
}
