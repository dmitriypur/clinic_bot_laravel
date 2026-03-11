<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Services\ShiftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftServiceTest extends TestCase
{
    private ShiftService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftService::class);
        $this->rebuildTestSchema();
    }

    public function test_create_normalizes_input_time_to_utc(): void
    {
        $context = $this->createContext();

        $shift = $this->service->create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15T10:00:00+03:00',
            'end_time' => '2026-03-15T11:00:00+03:00',
        ]);

        $this->assertSame('2026-03-15 07:00:00', $shift->getRawOriginal('start_time'));
        $this->assertSame('2026-03-15 08:00:00', $shift->getRawOriginal('end_time'));
    }

    public function test_create_rejects_when_end_time_is_not_after_start_time(): void
    {
        $context = $this->createContext();

        try {
            $this->service->create([
                'doctor_id' => $context['doctor']->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => '2026-03-15 10:00:00',
                'end_time' => '2026-03-15 10:00:00',
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['end_time' => ['Время конца должно быть позже начала']],
                $exception->errors()
            );
        }
    }

    public function test_create_rejects_overlapping_shift_for_same_doctor(): void
    {
        $context = $this->createContext();
        $otherCabinet = Cabinet::create([
            'name' => 'Кабинет 102',
            'status' => 1,
        ]);

        $this->service->create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15 08:00:00',
            'end_time' => '2026-03-15 09:00:00',
        ]);

        try {
            $this->service->create([
                'doctor_id' => $context['doctor']->id,
                'cabinet_id' => $otherCabinet->id,
                'start_time' => '2026-03-15 08:30:00',
                'end_time' => '2026-03-15 09:30:00',
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['doctor_id' => ['У врача есть пересечение в другом кабинете/время занято']],
                $exception->errors()
            );
        }
    }

    public function test_create_rejects_overlapping_shift_for_same_cabinet(): void
    {
        $context = $this->createContext();
        $otherDoctor = Doctor::create([
            'last_name' => 'Петров',
            'first_name' => 'Петр',
            'status' => 1,
        ]);

        $this->service->create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15 08:00:00',
            'end_time' => '2026-03-15 09:00:00',
        ]);

        try {
            $this->service->create([
                'doctor_id' => $otherDoctor->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => '2026-03-15 08:30:00',
                'end_time' => '2026-03-15 09:30:00',
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['cabinet_id' => ['В этом кабинете уже назначен врач в заданное время']],
                $exception->errors()
            );
        }
    }

    public function test_update_excludes_current_shift_from_overlap_checks(): void
    {
        $context = $this->createContext();

        $shift = $this->service->create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15 08:00:00',
            'end_time' => '2026-03-15 09:00:00',
        ]);

        $updated = $this->service->update($shift, [
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15 08:00:00',
            'end_time' => '2026-03-15 09:00:00',
        ]);

        $this->assertSame($shift->id, $updated->id);
        $this->assertSame('2026-03-15 05:00:00', $updated->getRawOriginal('start_time'));
        $this->assertSame('2026-03-15 06:00:00', $updated->getRawOriginal('end_time'));
    }

    public function test_create_force_deletes_soft_deleted_duplicate_before_insert(): void
    {
        $context = $this->createContext();

        $deletedShift = $this->service->create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15 08:00:00',
            'end_time' => '2026-03-15 09:00:00',
        ]);

        $deletedShift->delete();

        $created = $this->service->create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-15 08:00:00',
            'end_time' => '2026-03-15 09:00:00',
        ]);

        $this->assertSame(1, DoctorShift::withTrashed()->count());
        $this->assertSame(0, DoctorShift::onlyTrashed()->count());
        $this->assertDatabaseHas('doctor_shifts', [
            'id' => $created->id,
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(1, DoctorShift::withTrashed()->count());
    }

    private function createContext(): array
    {
        $doctor = Doctor::create([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'status' => 1,
        ]);

        $cabinet = Cabinet::create([
            'name' => 'Кабинет 101',
            'status' => 1,
        ]);

        return compact('doctor', 'cabinet');
    }

    private function rebuildTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'doctor_shifts',
            'cabinets',
            'doctors',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('second_name')->nullable();
            $table->integer('status')->default(1);
            $table->string('uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->integer('status')->default(1);
            $table->string('external_id')->nullable();
            $table->timestamps();
        });

        Schema::create('doctor_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id');
            $table->unsignedBigInteger('doctor_id');
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }
}
