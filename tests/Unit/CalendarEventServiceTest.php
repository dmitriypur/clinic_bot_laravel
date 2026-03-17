<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\Clinic;
use App\Models\User;
use App\Services\CalendarEventService;
use App\Services\Slots\SlotData;
use App\Services\Slots\SlotProviderFactory;
use App\Services\Slots\SlotProviderInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CalendarEventServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'calendar.colors.free_slot' => '#00ff00',
            'calendar.colors.occupied_slot' => '#ff0000',
        ]);

        $this->rebuildTestSchema();
    }

    public function test_generate_events_loads_applications_in_bulk_instead_of_querying_per_slot(): void
    {
        $clinic = Clinic::create([
            'name' => 'Тестовая клиника',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        DB::table('applications')->insert([
            'clinic_id' => $clinic->id,
            'cabinet_id' => 10,
            'doctor_id' => 20,
            'appointment_datetime' => '2026-03-21 09:00:00',
            'full_name' => 'Пациент 1',
            'phone' => '+79990000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('applications')->insert([
            'clinic_id' => $clinic->id,
            'cabinet_id' => 10,
            'doctor_id' => 20,
            'appointment_datetime' => '2026-03-21 09:30:00',
            'full_name' => 'Пациент 2',
            'phone' => '+79990000002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $slots = collect([
            new SlotData(
                id: 'slot-1',
                start: Carbon::parse('2026-03-21 09:00:00', 'UTC'),
                end: Carbon::parse('2026-03-21 09:30:00', 'UTC'),
                clinicId: $clinic->id,
                branchId: null,
                cabinetId: 10,
                doctorId: 20,
                source: 'local',
            ),
            new SlotData(
                id: 'slot-2',
                start: Carbon::parse('2026-03-21 09:30:00', 'UTC'),
                end: Carbon::parse('2026-03-21 10:00:00', 'UTC'),
                clinicId: $clinic->id,
                branchId: null,
                cabinetId: 10,
                doctorId: 20,
                source: 'local',
            ),
        ]);

        $provider = new class($slots) implements SlotProviderInterface
        {
            public function __construct(private readonly Collection $slots) {}

            public function getSlots(CarbonInterface $from, CarbonInterface $to, array $filters, User $user): Collection
            {
                return $this->slots;
            }
        };

        $factory = Mockery::mock(SlotProviderFactory::class);
        $factory->shouldReceive('make')->once()->withArgs(fn (Clinic $resolvedClinic) => $resolvedClinic->is($clinic))->andReturn($provider);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('isPartner')->andReturnFalse();
        $user->shouldReceive('isDoctor')->andReturnFalse();

        $service = new CalendarEventService($factory);

        DB::enableQueryLog();

        $events = $service->generateEvents([
            'start' => '2026-03-21T00:00:00+03:00',
            'end' => '2026-03-22T00:00:00+03:00',
        ], [
            'clinic_ids' => [$clinic->id],
        ], $user);

        $queries = collect(DB::getQueryLog());

        $applicationQueries = $queries->filter(fn (array $query) => str_contains($query['query'], 'from "applications"'))->count();

        $this->assertCount(2, $events);
        $this->assertSame(1, $applicationQueries);
        $this->assertSame('Пациент 1', $events[0]['title']);
        $this->assertSame('Пациент 2', $events[1]['title']);
    }

    private function rebuildTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'applications',
            'integration_endpoints',
            'branches',
            'cabinets',
            'doctors',
            'cities',
            'users',
            'clinics',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('status')->default(1);
            $table->integer('slot_duration')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('cabinet_id')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->timestamp('appointment_datetime')->nullable();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('appointment_status')->nullable();
            $table->timestamps();
        });
    }
}
