<?php

namespace Tests\Feature;

use App\Enums\IntegrationMode;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
            ->assertJsonPath('stats.branch-ext-1.blocked', 0);

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
            'external_mappings',
            'integration_endpoints',
            'branch_doctor',
            'clinic_doctor',
            'branches',
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
