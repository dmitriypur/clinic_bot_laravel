<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Services\MassShiftCreator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MassShiftCreatorTest extends TestCase
{
    private MassShiftCreator $creator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Moscow']);

        $this->creator = app(MassShiftCreator::class);
        $this->rebuildTestSchema();
    }

    public function test_create_series_splits_shift_by_break(): void
    {
        $context = $this->createContext();

        $created = $this->creator->createSeries([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-16 09:00:00',
            'end_time' => '2026-03-16 18:00:00',
            'has_break' => true,
            'break_start_time' => '13:00',
            'break_end_time' => '14:00',
        ]);

        $this->assertCount(2, $created);
        $this->assertSame(
            ['2026-03-16 06:00:00', '2026-03-16 11:00:00'],
            $created->map(fn (DoctorShift $shift) => $shift->getRawOriginal('start_time'))->all()
        );
        $this->assertSame(
            ['2026-03-16 10:00:00', '2026-03-16 15:00:00'],
            $created->map(fn (DoctorShift $shift) => $shift->getRawOriginal('end_time'))->all()
        );
    }

    public function test_create_series_skips_excluded_weekdays_and_applies_templates_for_full_days(): void
    {
        $context = $this->createContext();

        $created = $this->creator->createSeries([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-16 09:00:00',
            'end_time' => '2026-03-18 18:00:00',
            'workday_start' => '10:00',
            'workday_end' => '17:00',
            'excluded_weekdays' => [2],
        ]);

        $this->assertCount(2, $created);
        $this->assertSame(
            ['2026-03-16 06:00:00', '2026-03-18 07:00:00'],
            $created->map(fn (DoctorShift $shift) => $shift->getRawOriginal('start_time'))->all()
        );
        $this->assertSame(
            ['2026-03-16 14:00:00', '2026-03-18 15:00:00'],
            $created->map(fn (DoctorShift $shift) => $shift->getRawOriginal('end_time'))->all()
        );
    }

    public function test_create_series_rejects_break_covering_entire_shift(): void
    {
        $context = $this->createContext();

        try {
            $this->creator->createSeries([
                'doctor_id' => $context['doctor']->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => '2026-03-16 09:00:00',
                'end_time' => '2026-03-16 18:00:00',
                'has_break' => true,
                'break_start_time' => '09:00',
                'break_end_time' => '18:00',
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'break_start_time' => ['Перерыв покрывает смену полностью. Уберите перерыв или скорректируйте время.'],
            ], $exception->errors());
        }
    }

    public function test_create_series_wraps_shift_conflict_with_shift_date_message(): void
    {
        $context = $this->createContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => '2026-03-16 06:00:00',
            'end_time' => '2026-03-16 09:00:00',
        ]);

        try {
            $this->creator->createSeries([
                'doctor_id' => $context['doctor']->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => '2026-03-16 09:00:00',
                'end_time' => '2026-03-16 12:00:00',
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertSame(
                ['doctor_id' => ['У врача есть пересечение в другом кабинете/время занято']],
                ['doctor_id' => $errors['doctor_id']]
            );
            $this->assertSame(
                ['Не удалось создать смену на 16.03.2026. У врача есть пересечение в другом кабинете/время занято'],
                $errors['shift_date']
            );
        }
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
