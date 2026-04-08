<?php

namespace Tests\Feature;

use App\Jobs\SendCrmNotificationJob;
use App\Models\Application;
use App\Models\Branch;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\IntegrationEndpoint;
use App\Services\Admin\AdminApplicationService;
use App\Services\OneC\OneCBookingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AdminApplicationRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Moscow']);
        $this->rebuildTestSchema();
        Cache::flush();
        Carbon::setTestNow(Carbon::create(2025, 1, 1, 8, 0, 0, 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_admin_create_with_onec_and_crm_uses_onec_and_skips_crm_queue(): void
    {
        Queue::fake();

        $context = $this->createContext();
        $this->enableCrm($context['clinic']);
        $this->enableOneC($context['clinic'], $context['branch']);

        $bookingService = Mockery::mock(OneCBookingService::class);
        $bookingService->shouldReceive('bookDirect')
            ->once()
            ->andReturn(['status' => 'booked']);
        $this->app->instance(OneCBookingService::class, $bookingService);

        $application = app(AdminApplicationService::class)->create([
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'full_name' => 'Админ 1С',
            'phone' => '79990000101',
            'appointment_datetime' => '2025-01-02 09:00',
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
        ], [
            'appointment_source' => 'Админка',
        ]);

        $this->assertSame(Application::INTEGRATION_TYPE_ONEC, $application->integration_type);
        Queue::assertNotPushed(SendCrmNotificationJob::class);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'full_name' => 'Админ 1С',
        ]);
    }

    public function test_admin_create_without_datetime_with_crm_stays_local_and_dispatches_crm(): void
    {
        Queue::fake();

        $context = $this->createContext();
        $this->enableCrm($context['clinic']);
        $this->enableOneC($context['clinic'], $context['branch']);

        $bookingService = Mockery::mock(OneCBookingService::class);
        $bookingService->shouldNotReceive('book');
        $bookingService->shouldNotReceive('bookDirect');
        $this->app->instance(OneCBookingService::class, $bookingService);

        $application = app(AdminApplicationService::class)->create([
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'full_name' => 'Админ лид',
            'phone' => '79990000102',
            'promo_code' => 'PROMO',
            'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
        ], [
            'appointment_source' => 'Админка',
        ]);

        $this->assertSame(Application::INTEGRATION_TYPE_LOCAL, $application->integration_type);
        Queue::assertPushed(SendCrmNotificationJob::class, 1);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'full_name' => 'Админ лид',
        ]);
    }

    private function createContext(): array
    {
        $city = City::create([
            'name' => 'Simferopol',
            'status' => 1,
        ]);

        $clinic = Clinic::create([
            'name' => 'Админ клиника',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => $city->id,
            'name' => 'Админ филиал',
            'status' => 1,
            'integration_mode' => 'onec_push',
        ]);

        $doctor = Doctor::create([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'status' => 1,
            'external_id' => 'doctor-ext-admin',
        ]);

        return compact('city', 'clinic', 'branch', 'doctor');
    }

    private function enableCrm(Clinic $clinic): void
    {
        $clinic->forceFill([
            'crm_provider' => 'onec_crm',
            'crm_settings' => [
                'webhook_url' => 'https://example.test/webhook',
                'token' => 'test-token',
            ],
        ])->save();
    }

    private function enableOneC(Clinic $clinic, Branch $branch): void
    {
        IntegrationEndpoint::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'type' => IntegrationEndpoint::TYPE_ONEC,
            'is_active' => true,
        ]);
    }

    private function rebuildTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'applications',
            'integration_endpoints',
            'branches',
            'doctors',
            'clinics',
            'cities',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('status')->default(1);
            $table->integer('slot_duration')->nullable();
            $table->string('crm_provider')->nullable();
            $table->json('crm_settings')->nullable();
            $table->string('integration_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('city_id');
            $table->string('name');
            $table->integer('status')->default(1);
            $table->string('integration_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('second_name')->nullable();
            $table->integer('status')->default(1);
            $table->string('external_id')->nullable();
            $table->string('uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('cabinet_id')->nullable();
            $table->timestamp('appointment_datetime')->nullable();
            $table->string('full_name_parent')->nullable();
            $table->string('full_name');
            $table->string('birth_date')->nullable();
            $table->string('phone');
            $table->string('promo_code')->nullable();
            $table->unsignedBigInteger('tg_user_id')->nullable();
            $table->unsignedBigInteger('tg_chat_id')->nullable();
            $table->boolean('send_to_1c')->default(false);
            $table->string('appointment_status')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->string('external_appointment_id')->nullable();
            $table->string('integration_type')->nullable();
            $table->string('integration_status')->nullable();
            $table->json('integration_payload')->nullable();
            $table->timestamps();
        });
    }
}
