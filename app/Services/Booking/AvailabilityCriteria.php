<?php

namespace App\Services\Booking;

final readonly class AvailabilityCriteria
{
    public function __construct(
        public ?int $cityId = null,
        public array $clinicIds = [],
        public array $branchIds = [],
        public array $doctorIds = [],
        public ?string $specialty = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public string $timeFrom = '00:00',
        public string $timeTo = '23:59',
        public ?int $patientAge = null,
        public ?string $birthDate = null,
        public int $limit = 20,
    ) {}
}
