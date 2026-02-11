<?php

namespace App\Services\Slots;

use Carbon\CarbonInterface;

class SlotData
{
    public function __construct(
        public readonly string $id,
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
        public readonly int $clinicId,
        public readonly ?int $branchId,
        public readonly ?int $cabinetId,
        public readonly ?int $doctorId,
        public readonly string $source,
        public readonly bool $externallyOccupied = false,
        public readonly array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'clinic_id' => $this->clinicId,
            'branch_id' => $this->branchId,
            'cabinet_id' => $this->cabinetId,
            'doctor_id' => $this->doctorId,
            'source' => $this->source,
            'externally_occupied' => $this->externallyOccupied,
            'meta' => $this->meta,
        ];
    }
}
