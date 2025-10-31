<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class LegacySqlImportSeeder extends Seeder
{
    private string $dumpDirectory;

    public function __construct()
    {
        $this->dumpDirectory = base_path('sql_dump');
    }

    public function run(): void
    {
        if (!is_dir($this->dumpDirectory)) {
            throw new \RuntimeException(sprintf('Устаревший каталог дампа не найден: %s', $this->dumpDirectory));
        }

        DB::transaction(function () {
            $this->importCities();
            $this->importClinics();
            $this->importDoctors();
            $this->importUsers();
            $this->importClinicCityRelations();
            $this->importClinicDoctorRelations();
        });
    }

    private function importCities(): void
    {
        $rows = $this->parseInsertRows('cities.sql');

        foreach ($rows as $values) {
            [$id, $name, $status, $createdAt, $updatedAt] = $values + [null, null, null, null, null];

            $city = City::query()->firstOrNew(['id' => $id]);
            $city->fill([
                'name' => $name,
                'status' => (int) $status,
            ]);

            $city->timestamps = false;
            $city->created_at = $this->toCarbon($createdAt);
            $city->updated_at = $this->toCarbon($updatedAt);
            $city->saveQuietly();
            $city->timestamps = true;
        }

        $this->resetAutoIncrement('cities');
    }

    private function importClinics(): void
    {
        $rows = $this->parseInsertRows('clinics.sql');

        foreach ($rows as $values) {
            [$id, $name, $status, $createdAt, $updatedAt] = $values + [null, null, null, null, null];

            $clinic = Clinic::query()->firstOrNew(['id' => $id]);
            $clinic->fill([
                'name' => $name,
                'status' => (int) $status,
            ]);

            $clinic->timestamps = false;
            $clinic->created_at = $this->toCarbon($createdAt);
            $clinic->updated_at = $this->toCarbon($updatedAt);
            $clinic->saveQuietly();
            $clinic->timestamps = true;
        }

        $this->resetAutoIncrement('clinics');
    }

    private function importDoctors(): void
    {
        $rows = $this->parseInsertRows('doctors.sql');

        foreach ($rows as $values) {
            [
                $id,
                $lastName,
                $firstName,
                $secondName,
                $experience,
                $age,
                $photoSrc,
                $diplomaSrc,
                $status,
                $ageFrom,
                $ageTo,
                $sumRatings,
                $countRatings,
                $uuid,
                $reviewLink,
                $createdAt,
                $updatedAt,
            ] = $values + array_fill(0, 17, null);

            $doctor = Doctor::query()->firstOrNew(['id' => $id]);
            $doctor->fill([
                'last_name' => $lastName,
                'first_name' => $firstName,
                'second_name' => $secondName,
                'experience' => (int) $experience,
                'age' => (int) $age,
                'photo_src' => $this->normalizeJsonString($photoSrc),
                'diploma_src' => $this->normalizeJsonString($diplomaSrc),
                'status' => (int) $status,
                'age_admission_from' => (int) $ageFrom,
                'age_admission_to' => (int) $ageTo,
                'sum_ratings' => $sumRatings !== null ? (int) $sumRatings : 0,
                'count_ratings' => $countRatings !== null ? (int) $countRatings : 0,
                'uuid' => $uuid,
                'review_link' => $reviewLink,
            ]);

            $doctor->timestamps = false;
            $doctor->created_at = $this->toCarbon($createdAt);
            $doctor->updated_at = $this->toCarbon($updatedAt);
            $doctor->saveQuietly();
            $doctor->timestamps = true;
        }

        if (!empty($rows)) {
            $this->resetAutoIncrement('doctors');
        }
    }

    private function importUsers(): void
    {
        $rows = $this->parseInsertRows('users.sql');

        if (empty($rows)) {
            return;
        }

        $partnerRole = Role::findOrCreate('partner');

        $existingEmails = User::query()
            ->pluck('email')
            ->filter()
            ->all();
        $emailPool = array_fill_keys($existingEmails, true);

        foreach ($rows as $values) {
            [
                $id,
                $username,
                $legacyPassword,
                $status,
                $legacyRole,
                $clinicId,
                $createdAt,
                $updatedAt,
            ] = $values + array_fill(0, 8, null);

            unset($legacyPassword, $legacyRole);

            $user = User::query()->firstOrNew(['id' => $id]);
            $email = $this->makeEmailFromUsername($username, (int) $id, $emailPool);

            $attributes = [
                'name' => $username ?: ('Legacy User #' . $id),
                'email' => $email,
                'password' => '123456',
                'clinic_id' => $clinicId !== null ? (int) $clinicId : null,
            ];

            if (Schema::hasColumn('users', 'username')) {
                $attributes['username'] = $username;
            }

            if (Schema::hasColumn('users', 'status')) {
                $attributes['status'] = $status !== null ? (int) $status : null;
            }

            if (Schema::hasColumn('users', 'role')) {
                $attributes['role'] = 'partner';
            }

            $user->fill($attributes);

            $user->timestamps = false;
            $user->created_at = $this->toCarbon($createdAt);
            $user->updated_at = $this->toCarbon($updatedAt);
            $user->saveQuietly();
            $user->timestamps = true;

            $user->syncRoles([$partnerRole]);
        }

        $this->resetAutoIncrement('users');
    }

    private function importClinicCityRelations(): void
    {
        $rows = $this->parseInsertRows('clinics_cities.sql');

        foreach ($rows as $values) {
            [, $clinicId, $cityId] = $values + [null, null, null];

            if (!$clinicId || !$cityId) {
                continue;
            }

            DB::table('clinic_city')->updateOrInsert(
                [
                    'clinic_id' => (int) $clinicId,
                    'city_id' => (int) $cityId,
                ],
                []
            );
        }
    }

    private function importClinicDoctorRelations(): void
    {
        $rows = $this->parseInsertRows('clinics_doctors.sql');

        foreach ($rows as $values) {
            [, $clinicId, $doctorId] = $values + [null, null, null];

            if (!$clinicId || !$doctorId) {
                continue;
            }

            DB::table('clinic_doctor')->updateOrInsert(
                [
                    'clinic_id' => (int) $clinicId,
                    'doctor_id' => (int) $doctorId,
                ],
                []
            );
        }
    }

    private function parseInsertRows(string $fileName): array
    {
        $path = $this->getDumpPath($fileName);

        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $rows = [];
        $pattern = '/INSERT\s+INTO\s+.+?\s+VALUES\s*\((.*?)\);/is';
        preg_match_all($pattern, $content, $matches);

        foreach ($matches[1] ?? [] as $group) {
            $rows[] = $this->splitSqlValues($group);
        }

        return $rows;
    }

    private function splitSqlValues(string $valueString): array
    {
        $values = [];
        $buffer = '';
        $inString = false;
        $length = strlen($valueString);

        for ($i = 0; $i < $length; $i++) {
            $char = $valueString[$i];

            if ($char === "'") {
                if ($inString && $i + 1 < $length && $valueString[$i + 1] === "'") {
                    $buffer .= "'";
                    $i++;
                    continue;
                }

                $inString = !$inString;
                continue;
            }

            if ($char === ',' && !$inString) {
                $values[] = $this->normalizeSqlValue(trim($buffer));
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $values[] = $this->normalizeSqlValue(trim($buffer));
        }

        return $values;
    }

    private function normalizeSqlValue(?string $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $upper = strtoupper($value);
        if ($upper === 'NULL') {
            return null;
        }
        if ($upper === 'TRUE') {
            return true;
        }
        if ($upper === 'FALSE') {
            return false;
        }

        if (is_numeric($value) && !str_contains($value, 'e')) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    private function normalizeJsonString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            return $value;
        }

        return $value;
    }

    private function toCarbon($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function makeEmailFromUsername(?string $username, int $id, array &$emailPool): string
    {
        $base = $username ? Str::slug($username, '.') : null;
        if (!$base) {
            $base = 'legacy-user-' . $id;
        }

        $domain = 'legacy.imported';
        $candidate = $base . '@' . $domain;
        $suffix = 1;

        while (isset($emailPool[$candidate])) {
            $candidate = $base . '+' . $suffix . '@' . $domain;
            $suffix++;
        }

        $emailPool[$candidate] = true;

        return $candidate;
    }

    private function getDumpPath(string $fileName): string
    {
        return $this->dumpDirectory . DIRECTORY_SEPARATOR . $fileName;
    }

    private function resetAutoIncrement(string $table): void
    {
        $maxId = DB::table($table)->max('id');

        if ($maxId === null) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $sequence = sprintf('%s_id_seq', $table);
            try {
                DB::statement("SELECT setval('{$sequence}', {$maxId})");
            } catch (\Throwable) {
                // Ignore if sequence name differs
            }
        } elseif ($driver === 'mysql') {
            try {
                DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = " . ($maxId + 1));
            } catch (\Throwable) {
                // Ignore if permission is missing
            }
        }
    }
}
