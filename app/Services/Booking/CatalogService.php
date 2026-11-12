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
        if ($type === 'doctor') {
            $this->loadDoctors($branches);
        }

        $matches = $this->catalogMatches($branches, $type, $query);
        $approximate = false;
        if ($matches->isEmpty() && mb_strlen($query) >= 5) {
            $matches = $this->catalogMatches($branches, $type, $query, approximate: true);
            $approximate = $matches->isNotEmpty();
        }

        $matches = $matches->sortBy('id');
        $count = $matches->count();
        $meta = ['ambiguous' => $count > 1, 'has_more' => $count > $limit];
        if ($approximate) {
            $meta['approximate'] = true;
        }

        return [
            'data' => $matches->take($limit)->values()->all(),
            'meta' => $meta,
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

    private function catalogMatches(EloquentCollection $branches, string $type, string $query, bool $approximate = false): Collection
    {
        $matchesName = fn (?string $name): bool => $approximate
            ? $this->similarName($name ?? '', $query)
            : $this->contains($name, $query);

        return match ($type) {
            'doctor' => $this->doctorMatches($branches, $query, $approximate),
            'branch' => $branches
                ->filter(fn (Branch $branch) => $matchesName($branch->name)
                    || (! $approximate && $this->contains($branch->address, $query)))
                ->map(fn (Branch $branch) => $this->branchDetails($branch)),
            'city', 'clinic' => $branches->pluck($type)->unique('id')
                ->filter(fn ($place) => $matchesName($place->name))
                ->map(fn ($place) => ['id' => $place->id, 'name' => $place->name]),
        };
    }

    private function doctorMatches(EloquentCollection $branches, string $query, bool $approximate): Collection
    {
        $matches = [];

        foreach ($branches as $branch) {
            foreach ($branch->doctors as $doctor) {
                $declinedSurnames = $this->declinedSurnames($doctor->last_name);
                $matchesQuery = $approximate
                    ? $this->similarName($doctor->full_name, $query)
                        || collect($declinedSurnames)->contains(fn ($surname) => $this->similarName($surname, $query))
                    : $this->contains($doctor->full_name, $query)
                        || in_array($query, $declinedSurnames, true)
                        || $this->contains($doctor->specialty, $query);

                if (! $matchesQuery) {
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

    private function declinedSurnames(string $surname): array
    {
        $surname = $this->normalize($surname);
        if (! str_ends_with($surname, 'ая')) {
            return [];
        }

        $stem = Str::substr($surname, 0, -2);

        return [$stem.'ой', $stem.'ую'];
    }

    private function similarName(string $name, string $query): bool
    {
        $nameWords = explode(' ', $this->normalize($name));
        $wordCount = substr_count($query, ' ') + 1;
        $queryCharacters = mb_str_split($query);
        $maxEdits = count($queryCharacters) >= 8 ? 2 : 1;
        $queryNumbers = trim(preg_replace('/[^\p{N}]+/u', ' ', $query));

        for ($offset = 0; $offset <= count($nameWords) - $wordCount; $offset++) {
            $candidate = implode(' ', array_slice($nameWords, $offset, $wordCount));
            if (trim(preg_replace('/[^\p{N}]+/u', ' ', $candidate)) !== $queryNumbers
                || abs(mb_strlen($candidate) - count($queryCharacters)) > $maxEdits) {
                continue;
            }

            if ($this->editDistance(mb_str_split($candidate), $queryCharacters) <= $maxEdits) {
                return true;
            }
        }

        return false;
    }

    // PHP's levenshtein counts UTF-8 bytes, so compare Unicode characters instead.
    private function editDistance(array $name, array $query): int
    {
        $previous = range(0, count($query));
        foreach ($name as $i => $character) {
            $current = [$i + 1];
            foreach ($query as $j => $expected) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + (int) ($character !== $expected),
                );
            }
            $previous = $current;
        }

        return $previous[count($query)];
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace('ё', 'е', Str::lower($value))));
    }
}
