<?php

namespace App\Services\Booking;

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\OnecSlot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogService
{
    public function search(string $type, string $query, ?int $cityId, int $limit, ?array $allowedBranchIds): array
    {
        $branches = $this->branches($cityId, allowedBranchIds: $allowedBranchIds);
        $query = $this->normalize($query);
        $matches = match ($type) {
            'doctor' => $this->doctorMatches($branches, $query),
            'branch' => $branches
                ->filter(fn (Branch $branch) => $this->contains($branch->name, $query) || $this->contains($branch->address, $query))
                ->map(fn (Branch $branch) => $this->branchDetails($branch)),
            'city', 'clinic' => $branches->pluck($type)->unique('id')
                ->filter(fn ($place) => $this->contains($place->name, $query))
                ->map(fn ($place) => ['id' => $place->id, 'name' => $place->name]),
        };

        $matches = $matches->sortBy('id');
        $count = $matches->count();

        return [
            'data' => $matches->take($limit)->values()->all(),
            'meta' => ['ambiguous' => $count > 1, 'has_more' => $count > $limit],
        ];
    }

    /** @return EloquentCollection<int, Branch> */
    public function branches(
        ?int $cityId,
        array $clinicIds = [],
        array $branchIds = [],
        ?array $allowedBranchIds = null,
    ): EloquentCollection {
        return Branch::query()->with(['city:id,name', 'clinic:id,name,integration_mode,slot_duration'])
            ->where('status', 1)
            ->whereHas('clinic', fn ($query) => $query->where('status', 1))
            ->whereHas('city', fn ($query) => $query->where('status', 1))
            ->when($allowedBranchIds !== null, fn ($query) => $query->whereIn('id', $allowedBranchIds))
            ->when($cityId, fn ($query, $id) => $query->where('city_id', $id))
            ->when($clinicIds, fn ($query, $ids) => $query->whereIn('clinic_id', $ids))
            ->when($branchIds, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    public function loadDoctors(EloquentCollection $branches, array $doctorIds = []): void
    {
        $branches->load(['doctors' => fn ($query) => $query->where('doctors.status', 1)
            ->when($doctorIds, fn ($query, $ids) => $query->whereIn('doctors.id', $ids))
            ->orderBy('doctors.id')]);

        $onecBranches = $branches->filter(fn (Branch $branch) => $branch->isOnecPushMode()
            && $branch->doctors->contains(fn (Doctor $doctor) => blank($doctor->specialty)));
        if ($onecBranches->isEmpty()) {
            return;
        }

        // Resolve the existing labels within each branch. A local catalog value takes precedence.
        $labelRows = OnecSlot::query()->selectRaw('MAX(onec_slots.id)')
            ->join('branches', 'branches.id', '=', 'onec_slots.branch_id')
            ->whereColumn('onec_slots.clinic_id', 'branches.clinic_id')
            ->whereIn('branch_id', $onecBranches->modelKeys())
            ->whereIn('doctor_id', $onecBranches->flatMap->doctors->filter(fn (Doctor $doctor) => blank($doctor->specialty))->pluck('id')->unique())
            ->where(fn ($query) => $query
                ->where(fn ($label) => $label->whereNotNull('source_payload->doctor->espec')
                    ->where('source_payload->doctor->espec', '<>', ''))
                ->orWhere(fn ($label) => $label->whereNotNull('source_payload->doctor_speciality')
                    ->where('source_payload->doctor_speciality', '<>', '')))
            ->groupBy('branch_id', 'doctor_id');

        $labels = OnecSlot::query()->whereIn('id', $labelRows)
            ->get(['branch_id', 'doctor_id', 'source_payload'])
            ->keyBy(fn (OnecSlot $slot) => $slot->branch_id.':'.$slot->doctor_id);

        foreach ($onecBranches as $branch) {
            foreach ($branch->doctors as $doctor) {
                $payload = $labels->get($branch->id.':'.$doctor->id)?->source_payload;
                if (blank($doctor->specialty) && $payload) {
                    $label = data_get($payload, 'doctor.espec') ?: data_get($payload, 'doctor_speciality');
                    $doctor->specialty = is_string($label) ? trim($label) : null;
                }
            }
        }
    }

    public function branchDetails(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'city' => ['id' => $branch->city_id, 'name' => $branch->city->name],
            'clinic' => ['id' => $branch->clinic_id, 'name' => $branch->clinic->name],
        ];
    }

    public function matchesSpecialty(?string $specialty, string $query): bool
    {
        return $this->contains($specialty, $this->normalize($query));
    }

    private function doctorMatches(EloquentCollection $branches, string $query): Collection
    {
        $this->loadDoctors($branches);
        $matches = [];

        foreach ($branches as $branch) {
            foreach ($branch->doctors as $doctor) {
                if (! $this->contains($doctor->full_name, $query)
                    && ! $this->matchesDeclinedSurname($doctor->last_name, $query)
                    && ! $this->contains($doctor->specialty, $query)) {
                    continue;
                }

                $matches[$doctor->id] ??= ['id' => $doctor->id, 'name' => $doctor->full_name, 'specialties' => [], 'branches' => []];
                $matches[$doctor->id]['branches'][] = $this->branchDetails($branch);
                if (filled($doctor->specialty) && ! in_array($doctor->specialty, $matches[$doctor->id]['specialties'], true)) {
                    $matches[$doctor->id]['specialties'][] = $doctor->specialty;
                }
            }
        }

        return collect($matches);
    }

    private function contains(?string $value, string $normalizedQuery): bool
    {
        return $normalizedQuery !== '' && str_contains($this->normalize($value ?? ''), $normalizedQuery);
    }

    private function matchesDeclinedSurname(string $surname, string $normalizedQuery): bool
    {
        $surname = $this->normalize($surname);
        if (! str_ends_with($surname, 'ая')) {
            return false;
        }

        $stem = Str::substr($surname, 0, -2);

        return in_array($normalizedQuery, [$stem.'ой', $stem.'ую'], true);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace('ё', 'е', Str::lower($value))));
    }
}
