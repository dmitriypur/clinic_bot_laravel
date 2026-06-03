<?php

namespace Tests\Feature;

use App\Enums\IntegrationMode;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use App\Models\User;
use App\Services\CalendarFilterService;
use App\Services\Slots\OneCSlotProvider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OneCScheduleWebhookCompatibilityTest extends TestCase
{
    private string $rawLogPath;

    private string $dailyLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'logging.channels.daily.path' => storage_path('logs/test-onec-daily.log'),
        ]);

        $this->rawLogPath = storage_path('logs/onec-incoming-raw.log');
        $this->dailyLogPath = storage_path('logs/test-onec-daily.log');

        $this->cleanupTestLogs();
        $this->rebuildTestSchema();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestLogs();

        parent::tearDown();
    }

    public function test_schedule_webhook_accepts_prod_like_legacy_payload_and_imports_slots(): void
    {
        $context = $this->createPushSchedulingContext();
        $payload = $this->loadFixture('tests/Fixtures/onec/legacy_schedule_payload.json');

        $response = $this->withHeader('X-Integration-Token', 'secret-1')
            ->postJson(sprintf('/api/v1/integrations/%d/schedule', $context['clinic']->id), $payload);

        $response->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('stats.branch-ext-1.total_received', 3)
            ->assertJsonPath('stats.branch-ext-1.created', 3)
            ->assertJsonPath('stats.branch-ext-1.updated', 0)
            ->assertJsonPath('stats.branch-ext-1.deleted', 0);

        $this->assertSame(3, OnecSlot::query()->count());

        $this->assertDatabaseHas('onec_slots', [
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'external_slot_id' => 'branch-ext-1-doctor-ext-1-20260321-0900',
            'status' => OnecSlot::STATUS_FREE,
        ]);

        $this->assertDatabaseHas('onec_slots', [
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'external_slot_id' => 'branch-ext-1-doctor-ext-1-20260321-0930',
            'status' => OnecSlot::STATUS_BOOKED,
        ]);

        $this->assertDatabaseHas('onec_slots', [
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'external_slot_id' => 'branch-ext-1-doctor-ext-1-20260321-1000',
            'status' => OnecSlot::STATUS_FREE,
        ]);

        $this->assertNotNull($context['endpoint']->fresh()->last_success_at);
    }

    public function test_schedule_webhook_rejects_invalid_signature_without_touching_slots(): void
    {
        $context = $this->createPushSchedulingContext();
        $payload = $this->loadFixture('tests/Fixtures/onec/legacy_schedule_payload.json');

        $response = $this->withHeader('X-Integration-Token', 'wrong-secret')
            ->postJson(sprintf('/api/v1/integrations/%d/schedule', $context['clinic']->id), $payload);

        $response->assertUnauthorized();
        $this->assertSame(0, OnecSlot::query()->count());
        $this->assertNull($context['endpoint']->fresh()->last_success_at);
    }

    public function test_schedule_webhook_deletes_slots_missing_from_the_next_batch(): void
    {
        $context = $this->createPushSchedulingContext();
        $payload = $this->loadFixture('tests/Fixtures/onec/legacy_schedule_payload.json');

        $this->withHeader('X-Integration-Token', 'secret-1')
            ->postJson(sprintf('/api/v1/integrations/%d/schedule', $context['clinic']->id), $payload)
            ->assertOk();

        $payload['schedule']['data']['branch-ext-1']['doctor-ext-1']['cells'] = [
            $payload['schedule']['data']['branch-ext-1']['doctor-ext-1']['cells'][0],
            $payload['schedule']['data']['branch-ext-1']['doctor-ext-1']['cells'][2],
        ];

        $response = $this->withHeader('X-Integration-Token', 'secret-1')
            ->postJson(sprintf('/api/v1/integrations/%d/schedule', $context['clinic']->id), $payload);

        $response->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('stats.branch-ext-1.total_received', 2)
            ->assertJsonPath('stats.branch-ext-1.created', 0)
            ->assertJsonPath('stats.branch-ext-1.updated', 0)
            ->assertJsonPath('stats.branch-ext-1.deleted', 1);

        $this->assertSame(2, OnecSlot::query()->count());

        $this->assertDatabaseMissing('onec_slots', [
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'external_slot_id' => 'branch-ext-1-doctor-ext-1-20260321-0930',
        ]);
    }

    public function test_schedule_webhook_replaces_branch_doctors_with_doctors_from_latest_batch(): void
    {
        $context = $this->createPushSchedulingContext();
        $payload = $this->loadFixture('tests/Fixtures/onec/legacy_schedule_payload.json');

        $staleDoctor = Doctor::create([
            'last_name' => 'Старый',
            'first_name' => 'Врач',
            'second_name' => null,
            'status' => 1,
            'external_id' => 'stale-doctor-ext',
        ]);

        $context['branch']->doctors()->attach($staleDoctor->id);

        $this->withHeader('X-Integration-Token', 'secret-1')
            ->postJson(sprintf('/api/v1/integrations/%d/schedule', $context['clinic']->id), $payload)
            ->assertOk();

        $this->assertDatabaseHas('branch_doctor', [
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
        ]);

        $this->assertDatabaseMissing('branch_doctor', [
            'branch_id' => $context['branch']->id,
            'doctor_id' => $staleDoctor->id,
        ]);
    }

    public function test_clinic_doctors_endpoint_hides_stale_doctors_for_onec_branch_without_free_slots(): void
    {
        $context = $this->createPushSchedulingContext();

        $staleDoctor = Doctor::create([
            'last_name' => 'Старый',
            'first_name' => 'Врач',
            'second_name' => null,
            'status' => 1,
            'external_id' => 'stale-doctor-ext',
        ]);

        $context['clinic']->doctors()->attach([$context['doctor']->id, $staleDoctor->id]);
        $context['branch']->doctors()->attach([$context['doctor']->id, $staleDoctor->id]);

        OnecSlot::create([
            'clinic_id' => $context['clinic']->id,
            'doctor_id' => $context['doctor']->id,
            'branch_id' => $context['branch']->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addMinutes(30),
            'status' => OnecSlot::STATUS_FREE,
            'external_slot_id' => 'future-free-slot',
            'synced_at' => now(),
        ]);

        $response = $this->getJson(sprintf(
            '/api/v1/clinics/%d/doctors?branch_id=%d',
            $context['clinic']->id,
            $context['branch']->id
        ));

        $response->assertOk();

        $doctorIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($context['doctor']->id, $doctorIds);
        $this->assertNotContains($staleDoctor->id, $doctorIds);
    }

    public function test_clinic_doctors_endpoint_refreshes_cache_when_onec_branch_free_doctors_change(): void
    {
        $context = $this->createPushSchedulingContext();

        $slot = OnecSlot::create([
            'clinic_id' => $context['clinic']->id,
            'doctor_id' => $context['doctor']->id,
            'branch_id' => $context['branch']->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addMinutes(30),
            'status' => OnecSlot::STATUS_FREE,
            'external_slot_id' => 'cached-future-free-slot',
            'synced_at' => now(),
        ]);

        $url = sprintf(
            '/api/v1/clinics/%d/doctors?branch_id=%d',
            $context['clinic']->id,
            $context['branch']->id
        );

        $firstResponse = $this->getJson($url);
        $firstResponse->assertOk();
        $this->assertContains($context['doctor']->id, collect($firstResponse->json('data'))->pluck('id')->all());

        $slot->update([
            'status' => OnecSlot::STATUS_BLOCKED,
            'synced_at' => now()->addSecond(),
        ]);

        $secondResponse = $this->getJson($url);
        $secondResponse->assertOk();

        $this->assertNotContains($context['doctor']->id, collect($secondResponse->json('data'))->pluck('id')->all());
    }

    public function test_admin_doctor_filter_uses_future_free_onec_slots_for_branch_options(): void
    {
        $context = $this->createPushSchedulingContext();

        $staleDoctor = Doctor::create([
            'last_name' => 'Старый',
            'first_name' => 'Врач',
            'second_name' => null,
            'status' => 1,
            'external_id' => 'stale-doctor-ext',
        ]);

        $context['branch']->doctors()->attach([$context['doctor']->id, $staleDoctor->id]);

        OnecSlot::create([
            'clinic_id' => $context['clinic']->id,
            'doctor_id' => $context['doctor']->id,
            'branch_id' => $context['branch']->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addMinutes(30),
            'status' => OnecSlot::STATUS_FREE,
            'external_slot_id' => 'admin-filter-future-free-slot',
            'synced_at' => now(),
        ]);

        OnecSlot::create([
            'clinic_id' => $context['clinic']->id,
            'doctor_id' => $staleDoctor->id,
            'branch_id' => $context['branch']->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addMinutes(30),
            'status' => OnecSlot::STATUS_FREE,
            'external_slot_id' => 'admin-filter-past-free-slot',
            'synced_at' => now(),
        ]);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('isDoctor')->andReturnFalse();

        $options = app(CalendarFilterService::class)->getAvailableDoctors($user, [$context['branch']->id]);

        $this->assertArrayHasKey($context['doctor']->id, $options);
        $this->assertArrayNotHasKey($staleDoctor->id, $options);
    }

    public function test_admin_doctor_filter_uses_future_local_shifts_for_branch_options(): void
    {
        $clinic = Clinic::create([
            'name' => 'Локальная клиника',
            'status' => 1,
            'slot_duration' => 30,
            'integration_mode' => IntegrationMode::LOCAL->value,
        ]);

        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => 1,
            'name' => 'Local branch',
            'status' => 1,
            'slot_duration' => 30,
            'integration_mode' => IntegrationMode::LOCAL->value,
        ]);

        $doctorWithShift = Doctor::create([
            'last_name' => 'Будущий',
            'first_name' => 'Врач',
            'second_name' => null,
            'status' => 1,
        ]);

        $doctorWithoutShift = Doctor::create([
            'last_name' => 'Пустой',
            'first_name' => 'Врач',
            'second_name' => null,
            'status' => 1,
        ]);

        $branch->doctors()->attach([$doctorWithShift->id, $doctorWithoutShift->id]);

        $cabinet = Cabinet::create([
            'branch_id' => $branch->id,
            'name' => 'Кабинет 1',
            'status' => 1,
        ]);

        DoctorShift::create([
            'cabinet_id' => $cabinet->id,
            'doctor_id' => $doctorWithShift->id,
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
        ]);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('isDoctor')->andReturnFalse();

        $options = app(CalendarFilterService::class)->getAvailableDoctors($user, [$branch->id]);

        $this->assertArrayHasKey($doctorWithShift->id, $options);
        $this->assertArrayNotHasKey($doctorWithoutShift->id, $options);
    }

    public function test_onec_slot_provider_treats_blocked_slots_as_unavailable(): void
    {
        $context = $this->createPushSchedulingContext();

        OnecSlot::create([
            'clinic_id' => $context['clinic']->id,
            'doctor_id' => $context['doctor']->id,
            'branch_id' => $context['branch']->id,
            'start_at' => Carbon::parse('2026-03-21 09:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-03-21 09:30:00', 'UTC'),
            'status' => OnecSlot::STATUS_FREE,
            'external_slot_id' => 'slot-free',
            'synced_at' => now(),
        ]);

        OnecSlot::create([
            'clinic_id' => $context['clinic']->id,
            'doctor_id' => $context['doctor']->id,
            'branch_id' => $context['branch']->id,
            'start_at' => Carbon::parse('2026-03-21 09:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-03-21 09:30:00', 'UTC'),
            'status' => OnecSlot::STATUS_BLOCKED,
            'external_slot_id' => 'slot-blocked',
            'synced_at' => now()->addSecond(),
        ]);

        $provider = new OneCSlotProvider($context['clinic'], app(CalendarFilterService::class));
        $user = Mockery::mock(User::class);
        $user->shouldReceive('isDoctor')->andReturnFalse();

        $slots = $provider->getSlots(
            Carbon::parse('2026-03-21 00:00:00', 'UTC'),
            Carbon::parse('2026-03-21 23:59:59', 'UTC'),
            [],
            $user
        );

        $this->assertCount(1, $slots);
        $this->assertTrue($slots->first()->externallyOccupied);
        $this->assertSame('slot-blocked', $slots->first()->meta['onec_slot_id']);
        $this->assertSame(OnecSlot::STATUS_BLOCKED, $slots->first()->meta['slot_status']);
    }

    private function createPushSchedulingContext(): array
    {
        $clinic = Clinic::create([
            'name' => 'Клиника 1С',
            'status' => 1,
            'slot_duration' => 30,
            'integration_mode' => IntegrationMode::ONEC_PUSH->value,
        ]);

        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => 1,
            'name' => 'Push branch',
            'status' => 1,
            'slot_duration' => 30,
            'external_id' => 'branch-ext-1',
            'integration_mode' => IntegrationMode::ONEC_PUSH->value,
        ]);

        $doctor = Doctor::create([
            'last_name' => 'Тестов',
            'first_name' => 'Тест',
            'second_name' => 'Тестович',
            'status' => 1,
            'external_id' => 'doctor-ext-1',
        ]);

        $endpoint = IntegrationEndpoint::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'type' => IntegrationEndpoint::TYPE_ONEC,
            'is_active' => true,
            'credentials' => [
                'webhook_secret' => 'secret-1',
            ],
        ]);

        return compact('clinic', 'branch', 'doctor', 'endpoint');
    }

    private function loadFixture(string $relativePath): array
    {
        $contents = file_get_contents(base_path($relativePath));

        return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function cleanupTestLogs(): void
    {
        foreach ([$this->rawLogPath ?? null, $this->dailyLogPath ?? null] as $path) {
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Feature tests intentionally use a minimal schema to avoid coupling
     * to historical project migrations that are not SQLite-compatible.
     */
    private function rebuildTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'onec_slots',
            'doctor_shifts',
            'cabinets',
            'external_mappings',
            'integration_endpoints',
            'branch_doctor',
            'clinic_doctor',
            'branches',
            'cities',
            'doctors',
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
            $table->string('external_id')->nullable();
            $table->string('crm_provider')->nullable();
            $table->json('crm_settings')->nullable();
            $table->boolean('dashboard_calendar_enabled')->nullable();
            $table->string('integration_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('second_name')->nullable();
            $table->integer('experience')->nullable();
            $table->integer('age')->nullable();
            $table->string('photo_src')->nullable();
            $table->string('diploma_src')->nullable();
            $table->integer('status')->default(1);
            $table->integer('age_admission_from')->nullable();
            $table->integer('age_admission_to')->nullable();
            $table->integer('sum_ratings')->default(0);
            $table->integer('count_ratings')->default(0);
            $table->string('uuid')->nullable();
            $table->string('review_link')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->integer('status')->default(1);
            $table->integer('slot_duration')->nullable();
            $table->string('external_id')->nullable();
            $table->string('integration_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_doctor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('doctor_id');
        });

        Schema::create('branch_doctor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('doctor_id');
        });

        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('doctor_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id');
            $table->unsignedBigInteger('doctor_id');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('integration_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->string('base_url')->nullable();
            $table->string('auth_type')->nullable();
            $table->json('credentials')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('external_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->string('local_type');
            $table->unsignedBigInteger('local_id');
            $table->string('external_id');
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('onec_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('cabinet_id')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('status')->nullable();
            $table->string('external_slot_id');
            $table->string('booking_uuid')->nullable();
            $table->string('payload_hash')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }
}
