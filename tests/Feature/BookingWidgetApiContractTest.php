<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\IntegrationEndpoint;
use App\Jobs\SendCrmNotificationJob;
use App\Services\OneC\OneCBookingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use Tests\TestCase;

class BookingWidgetApiContractTest extends TestCase
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

        parent::tearDown();
    }

    public function test_cities_endpoint_preserves_booking_widget_collection_contract(): void
    {
        $activeCity = City::create([
            'name' => 'Simferopol',
            'status' => 1,
        ]);

        City::create([
            'name' => 'Hidden City',
            'status' => 0,
        ]);

        $response = $this->getJson('/api/v1/cities');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'status'],
                ],
            ]);

        $this->assertSame([$activeCity->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_city_clinics_and_clinic_branches_preserve_widget_contract(): void
    {
        $context = $this->createLocalSchedulingContext();

        $cityClinicsResponse = $this->getJson(sprintf(
            '/api/v1/cities/%d/clinics',
            $context['city']->id
        ));

        $cityClinicsResponse->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'branches' => [
                            '*' => ['id', 'name'],
                        ],
                    ],
                ],
            ]);

        $branchesResponse = $this->getJson(sprintf(
            '/api/v1/clinics/%d/branches?city_id=%d',
            $context['clinic']->id,
            $context['city']->id
        ));

        $branchesResponse->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'address', 'phone', 'external_id'],
                ],
            ])
            ->assertJsonPath('data.0.id', $context['branch']->id)
            ->assertJsonPath('data.0.name', $context['branch']->name)
            ->assertJsonPath('data.0.address', $context['branch']->address)
            ->assertJsonPath('data.0.phone', $context['branch']->phone)
            ->assertJsonPath('data.0.external_id', $context['branch']->external_id);
    }

    public function test_doctor_collections_preserve_widget_contract(): void
    {
        $context = $this->createLocalSchedulingContext();

        $expectedDoctorShape = [
            'id',
            'name',
            'experience',
            'age',
            'photo_src',
            'diploma_src',
            'status',
            'age_admission_from',
            'age_admission_to',
            'uuid',
            'review_link',
            'external_id',
        ];

        $cityDoctorsResponse = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors',
            $context['city']->id
        ));

        $cityDoctorsResponse->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => $expectedDoctorShape,
                ],
            ])
            ->assertJsonPath('data.0.id', $context['doctor']->id)
            ->assertJsonPath('data.0.external_id', $context['doctor']->external_id);

        $clinicDoctorsResponse = $this->getJson(sprintf(
            '/api/v1/clinics/%d/doctors?branch_id=%d&birth_date=2010-01-01',
            $context['clinic']->id,
            $context['branch']->id
        ));

        $clinicDoctorsResponse->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => $expectedDoctorShape,
                ],
            ])
            ->assertJsonPath('data.0.id', $context['doctor']->id)
            ->assertJsonPath('data.0.uuid', $context['doctor']->uuid);
    }

    public function test_doctors_by_date_endpoint_returns_aggregated_doctor_cards_for_selected_day(): void
    {
        $context = $this->createLocalSchedulingContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 2, 6, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 2, 7, 0, 0, 'UTC'),
        ]);

        $this->createOccupiedApplication(
            context: $context,
            appointmentDateTime: Carbon::create(2025, 1, 2, 9, 30, 0, 'Europe/Moscow')
        );

        $response = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors-by-date?date=2025-01-02&birth_date=2010-01-01',
            $context['city']->id
        ));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'date',
                        'doctor_id',
                        'branch_id',
                        'clinic_id',
                        'name',
                        'experience',
                        'age',
                        'photo_src',
                        'diploma_src',
                        'status',
                        'age_admission_from',
                        'age_admission_to',
                        'uuid',
                        'review_link',
                        'external_id',
                        'speciality',
                        'branch_name',
                        'branch_address',
                        'clinic_name',
                        'available_slots',
                        'first_available_time',
                    ],
                ],
            ])
            ->assertJsonPath('data.0.doctor_id', $context['doctor']->id)
            ->assertJsonPath('data.0.branch_id', $context['branch']->id)
            ->assertJsonPath('data.0.clinic_id', $context['clinic']->id)
            ->assertJsonPath('data.0.date', '2025-01-02')
            ->assertJsonPath('data.0.available_slots', 1)
            ->assertJsonPath('data.0.first_available_time', '09:00');
    }

    public function test_doctors_by_date_endpoint_respects_nullable_age_boundaries(): void
    {
        $context = $this->createLocalSchedulingContext();

        $teenDoctor = Doctor::create([
            'last_name' => 'Петров',
            'first_name' => 'Пётр',
            'second_name' => 'Петрович',
            'experience' => 8,
            'age' => 38,
            'status' => 1,
            'age_admission_from' => null,
            'age_admission_to' => 17,
            'review_link' => 'https://example.test/doctors/petrov',
            'external_id' => 'doctor-ext-petrov',
        ]);

        $infantDoctor = Doctor::create([
            'last_name' => 'Сидорова',
            'first_name' => 'Анна',
            'second_name' => 'Игоревна',
            'experience' => 6,
            'age' => 34,
            'status' => 1,
            'age_admission_from' => 2,
            'age_admission_to' => null,
            'review_link' => 'https://example.test/doctors/sidorova',
            'external_id' => 'doctor-ext-sidorova',
        ]);

        $context['clinic']->doctors()->attach([$teenDoctor->id, $infantDoctor->id]);
        $context['branch']->doctors()->attach([$teenDoctor->id, $infantDoctor->id]);

        DoctorShift::insert([
            [
                'doctor_id' => $teenDoctor->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => Carbon::create(2025, 1, 2, 8, 0, 0, 'UTC'),
                'end_time' => Carbon::create(2025, 1, 2, 9, 0, 0, 'UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'doctor_id' => $infantDoctor->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => Carbon::create(2025, 1, 2, 10, 0, 0, 'UTC'),
                'end_time' => Carbon::create(2025, 1, 2, 11, 0, 0, 'UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $adultResponse = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors-by-date?date=2025-01-02&birth_date=2000-01-01',
            $context['city']->id
        ));

        $adultDoctorIds = collect($adultResponse->json('data'))->pluck('doctor_id')->all();

        $this->assertContains($infantDoctor->id, $adultDoctorIds);
        $this->assertNotContains($teenDoctor->id, $adultDoctorIds);

        $childResponse = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors-by-date?date=2025-01-02&birth_date=2015-01-01',
            $context['city']->id
        ));

        $childDoctorIds = collect($childResponse->json('data'))->pluck('doctor_id')->all();

        $this->assertContains($teenDoctor->id, $childDoctorIds);
        $this->assertContains($infantDoctor->id, $childDoctorIds);
    }

    public function test_doctors_by_date_calendar_endpoint_returns_month_aggregates_for_city_flow(): void
    {
        $context = $this->createLocalSchedulingContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 2, 6, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 2, 7, 0, 0, 'UTC'),
        ]);

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 3, 8, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 3, 9, 0, 0, 'UTC'),
        ]);

        $this->createOccupiedApplication(
            context: $context,
            appointmentDateTime: Carbon::create(2025, 1, 2, 9, 30, 0, 'Europe/Moscow')
        );

        $response = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors-by-date/calendar?date_from=2025-01-02&date_to=2025-01-03&birth_date=2010-01-01',
            $context['city']->id
        ));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['date', 'total_slots', 'available_slots', 'available_doctors', 'first_available_time'],
                ],
            ]);

        $byDate = collect($response->json('data'))->keyBy('date');

        $this->assertSame(1, $byDate['2025-01-02']['total_slots']);
        $this->assertSame(1, $byDate['2025-01-02']['available_slots']);
        $this->assertSame(1, $byDate['2025-01-02']['available_doctors']);
        $this->assertSame('09:00', $byDate['2025-01-02']['first_available_time']);

        $this->assertSame(2, $byDate['2025-01-03']['total_slots']);
        $this->assertSame(2, $byDate['2025-01-03']['available_slots']);
        $this->assertSame(1, $byDate['2025-01-03']['available_doctors']);
        $this->assertSame('11:00', $byDate['2025-01-03']['first_available_time']);
    }

    public function test_doctors_by_date_calendar_endpoint_respects_nullable_age_boundaries(): void
    {
        $context = $this->createLocalSchedulingContext();

        $teenDoctor = Doctor::create([
            'last_name' => 'Петров',
            'first_name' => 'Пётр',
            'second_name' => 'Петрович',
            'experience' => 8,
            'age' => 38,
            'status' => 1,
            'age_admission_from' => null,
            'age_admission_to' => 17,
            'review_link' => 'https://example.test/doctors/petrov',
            'external_id' => 'doctor-ext-petrov',
        ]);

        $adultDoctor = Doctor::create([
            'last_name' => 'Сидорова',
            'first_name' => 'Анна',
            'second_name' => 'Игоревна',
            'experience' => 6,
            'age' => 34,
            'status' => 1,
            'age_admission_from' => 2,
            'age_admission_to' => null,
            'review_link' => 'https://example.test/doctors/sidorova',
            'external_id' => 'doctor-ext-sidorova',
        ]);

        $context['clinic']->doctors()->attach([$teenDoctor->id, $adultDoctor->id]);
        $context['branch']->doctors()->attach([$teenDoctor->id, $adultDoctor->id]);

        DoctorShift::insert([
            [
                'doctor_id' => $teenDoctor->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => Carbon::create(2025, 1, 2, 8, 0, 0, 'UTC'),
                'end_time' => Carbon::create(2025, 1, 2, 9, 0, 0, 'UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'doctor_id' => $adultDoctor->id,
                'cabinet_id' => $context['cabinet']->id,
                'start_time' => Carbon::create(2025, 1, 2, 10, 0, 0, 'UTC'),
                'end_time' => Carbon::create(2025, 1, 2, 11, 0, 0, 'UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $adultResponse = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors-by-date/calendar?date_from=2025-01-02&date_to=2025-01-02&birth_date=2000-01-01',
            $context['city']->id
        ));

        $adultDay = collect($adultResponse->json('data'))->firstWhere('date', '2025-01-02');
        $this->assertSame(2, $adultDay['available_slots']);
        $this->assertSame(1, $adultDay['available_doctors']);
        $this->assertSame('13:00', $adultDay['first_available_time']);

        $childResponse = $this->getJson(sprintf(
            '/api/v1/cities/%d/doctors-by-date/calendar?date_from=2025-01-02&date_to=2025-01-02&birth_date=2015-01-01',
            $context['city']->id
        ));

        $childDay = collect($childResponse->json('data'))->firstWhere('date', '2025-01-02');
        $this->assertSame(4, $childDay['available_slots']);
        $this->assertSame(2, $childDay['available_doctors']);
        $this->assertSame('11:00', $childDay['first_available_time']);
    }

    public function test_local_slots_endpoint_preserves_widget_slot_shape(): void
    {
        $context = $this->createLocalSchedulingContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 2, 6, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 2, 7, 0, 0, 'UTC'),
        ]);

        $this->createOccupiedApplication(
            context: $context,
            appointmentDateTime: Carbon::create(2025, 1, 2, 9, 30, 0, 'Europe/Moscow')
        );

        $response = $this->getJson(sprintf(
            '/api/v1/doctors/%d/slots?date=2025-01-02&clinic_id=%d&branch_id=%d',
            $context['doctor']->id,
            $context['clinic']->id,
            $context['branch']->id
        ));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'shift_id',
                        'cabinet_id',
                        'branch_id',
                        'clinic_id',
                        'branch_name',
                        'clinic_name',
                        'cabinet_name',
                        'time',
                        'datetime',
                        'duration',
                        'is_past',
                        'is_occupied',
                        'is_available',
                        'onec_slot_id',
                    ],
                ],
            ]);

        $slotsByTime = collect($response->json('data'))->keyBy('time');

        $this->assertTrue($slotsByTime->has('09:00'));
        $this->assertTrue($slotsByTime->has('09:30'));
        $this->assertTrue($slotsByTime['09:00']['is_available']);
        $this->assertFalse($slotsByTime['09:00']['is_occupied']);
        $this->assertFalse($slotsByTime['09:30']['is_available']);
        $this->assertTrue($slotsByTime['09:30']['is_occupied']);
        $this->assertNull($slotsByTime['09:00']['onec_slot_id']);
    }

    public function test_calendar_availability_endpoint_preserves_widget_contract(): void
    {
        $context = $this->createLocalSchedulingContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 2, 6, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 2, 7, 0, 0, 'UTC'),
        ]);

        $this->createOccupiedApplication(
            context: $context,
            appointmentDateTime: Carbon::create(2025, 1, 2, 9, 30, 0, 'Europe/Moscow')
        );

        $response = $this->getJson(sprintf(
            '/api/v1/booking/calendar-availability?doctor_id=%d&clinic_id=%d&branch_id=%d&date_from=2025-01-02&date_to=2025-01-02',
            $context['doctor']->id,
            $context['clinic']->id,
            $context['branch']->id
        ));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['date', 'total_slots', 'available_slots', 'first_available_time'],
                ],
            ])
            ->assertJsonPath('data.0.date', '2025-01-02')
            ->assertJsonPath('data.0.total_slots', 2)
            ->assertJsonPath('data.0.available_slots', 1)
            ->assertJsonPath('data.0.first_available_time', '09:00');
    }

    public function test_check_slot_endpoint_preserves_success_contract_for_local_mode(): void
    {
        $context = $this->createLocalSchedulingContext();

        $response = $this->postJson('/api/v1/applications/check-slot', [
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'onec_slot_id' => 'local-flow-no-onec-required',
        ]);

        $response->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_create_application_preserves_success_resource_shape_for_local_booking(): void
    {
        $context = $this->createLocalSchedulingContext();

        $response = $this->postJson('/api/v1/applications', [
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'appointment_datetime' => '2025-01-02 09:00',
            'onec_slot_id' => null,
            'full_name' => 'Тестовый Пациент',
            'full_name_parent' => 'Тестовый Родитель',
            'birth_date' => '2010-01-01',
            'phone' => '79990000000',
            'promo_code' => 'PROMO',
            'comment' => 'Комментарий',
            'appointment_source' => 'site',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'city_id',
                    'clinic_id',
                    'branch_id',
                    'doctor_id',
                    'cabinet_id',
                    'full_name_parent',
                    'full_name',
                    'birth_date',
                    'appointment_datetime',
                    'phone',
                    'promo_code',
                    'tg_user_id',
                    'tg_chat_id',
                    'send_to_1c',
                    'integration_type',
                    'integration_status',
                    'external_appointment_id',
                    'integration_payload',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.city_id', $context['city']->id)
            ->assertJsonPath('data.clinic_id', $context['clinic']->id)
            ->assertJsonPath('data.branch_id', $context['branch']->id)
            ->assertJsonPath('data.doctor_id', $context['doctor']->id)
            ->assertJsonPath('data.cabinet_id', $context['cabinet']->id)
            ->assertJsonPath('data.full_name', 'Тестовый Пациент')
            ->assertJsonPath('data.integration_type', Application::INTEGRATION_TYPE_LOCAL);

        $this->assertDatabaseHas('applications', [
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'full_name' => 'Тестовый Пациент',
            'phone' => '79990000000',
            'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
        ]);
    }

    public function test_create_application_preserves_validation_error_shape(): void
    {
        $response = $this->postJson('/api/v1/applications', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'city_id',
                    'full_name',
                    'phone',
                ],
            ])
            ->assertJsonValidationErrors(['city_id', 'full_name', 'phone']);
    }

    public function test_create_application_with_onec_and_crm_sends_only_to_onec_and_stays_local(): void
    {
        Queue::fake();

        $context = $this->createLocalSchedulingContext();
        $this->enableCrm($context['clinic']);
        $this->enableOneC($context['clinic'], $context['branch']);

        $bookingService = Mockery::mock(OneCBookingService::class);
        $bookingService->shouldReceive('bookDirect')
            ->once()
            ->andReturn(['status' => 'booked']);
        $this->app->instance(OneCBookingService::class, $bookingService);

        $response = $this->postJson('/api/v1/applications', [
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'appointment_datetime' => '2025-01-02 09:00',
            'full_name' => 'Пациент 1С',
            'phone' => '79990000001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.integration_type', Application::INTEGRATION_TYPE_ONEC);

        Queue::assertNotPushed(SendCrmNotificationJob::class);
        $this->assertDatabaseHas('applications', [
            'full_name' => 'Пациент 1С',
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
        ]);
    }

    public function test_create_application_with_crm_only_and_datetime_dispatches_crm_and_stays_local(): void
    {
        Queue::fake();

        $context = $this->createLocalSchedulingContext();
        $this->enableCrm($context['clinic']);

        $response = $this->postJson('/api/v1/applications', [
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'appointment_datetime' => '2025-01-02 09:00',
            'full_name' => 'CRM запись',
            'phone' => '79990000002',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.integration_type', Application::INTEGRATION_TYPE_LOCAL);

        Queue::assertPushed(SendCrmNotificationJob::class, 1);
        $this->assertDatabaseHas('applications', [
            'full_name' => 'CRM запись',
            'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
        ]);
    }

    public function test_create_application_without_datetime_with_crm_dispatches_crm_and_does_not_require_onec(): void
    {
        Queue::fake();

        $context = $this->createLocalSchedulingContext();
        $this->enableCrm($context['clinic']);
        $this->enableOneC($context['clinic'], $context['branch']);

        $bookingService = Mockery::mock(OneCBookingService::class);
        $bookingService->shouldNotReceive('book');
        $bookingService->shouldNotReceive('bookDirect');
        $this->app->instance(OneCBookingService::class, $bookingService);

        $response = $this->postJson('/api/v1/applications', [
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'full_name' => 'Лид без времени',
            'phone' => '79990000003',
            'promo_code' => 'PROMO',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.integration_type', Application::INTEGRATION_TYPE_LOCAL)
            ->assertJsonPath('data.appointment_datetime', null);

        Queue::assertPushed(SendCrmNotificationJob::class, 1);
        $this->assertDatabaseHas('applications', [
            'full_name' => 'Лид без времени',
            'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
        ]);
    }

    public function test_create_application_without_datetime_and_without_crm_stays_local_only(): void
    {
        Queue::fake();

        $context = $this->createLocalSchedulingContext();

        $response = $this->postJson('/api/v1/applications', [
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'full_name' => 'Локальный лид',
            'phone' => '79990000004',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.integration_type', Application::INTEGRATION_TYPE_LOCAL);

        Queue::assertNotPushed(SendCrmNotificationJob::class);
        $this->assertDatabaseHas('applications', [
            'full_name' => 'Локальный лид',
            'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
        ]);
    }

    private function createLocalSchedulingContext(): array
    {
        $city = City::create([
            'name' => 'Simferopol',
            'status' => 1,
        ]);

        $clinic = Clinic::create([
            'name' => 'Клиника на Кирова',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $clinic->cities()->attach($city->id);

        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => $city->id,
            'name' => 'Филиал Центр',
            'address' => 'ул. Кирова, 10',
            'phone' => '+79990000000',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $cabinet = Cabinet::create([
            'branch_id' => $branch->id,
            'name' => 'Кабинет 101',
            'status' => 1,
        ]);

        $doctor = Doctor::create([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'second_name' => 'Иванович',
            'experience' => 10,
            'age' => 40,
            'status' => 1,
            'age_admission_from' => 0,
            'age_admission_to' => 99,
            'review_link' => 'https://example.test/doctors/ivanov',
            'external_id' => 'doctor-ext-ivanov',
        ]);

        $clinic->doctors()->attach($doctor->id);
        $branch->doctors()->attach($doctor->id);

        return compact('city', 'clinic', 'branch', 'cabinet', 'doctor');
    }

    private function createOccupiedApplication(array $context, Carbon $appointmentDateTime): Application
    {
        return Application::withoutEvents(function () use ($context, $appointmentDateTime) {
            return Application::create([
                'city_id' => $context['city']->id,
                'clinic_id' => $context['clinic']->id,
                'branch_id' => $context['branch']->id,
                'doctor_id' => $context['doctor']->id,
                'cabinet_id' => $context['cabinet']->id,
                'full_name' => 'Уже занятый слот',
                'phone' => '79991112233',
                'appointment_datetime' => $appointmentDateTime,
                'source' => Application::SOURCE_FRONTEND,
                'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
            ]);
        });
    }

    private function enableCrm(Clinic $clinic, string $provider = 'onec_crm'): void
    {
        $clinic->forceFill([
            'crm_provider' => $provider,
            'crm_settings' => [
                'webhook_url' => 'https://example.test/webhook',
                'token' => 'test-token',
            ],
        ])->save();
    }

    private function enableOneC(Clinic $clinic, Branch $branch): void
    {
        $branch->forceFill([
            'integration_mode' => 'onec_push',
        ])->save();

        IntegrationEndpoint::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'type' => IntegrationEndpoint::TYPE_ONEC,
            'is_active' => true,
        ]);
    }

    /**
     * Contract tests intentionally use a minimal schema to avoid coupling
     * to project-wide historical migrations that are not SQLite-compatible.
     */
    private function rebuildTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'applications',
            'doctor_shifts',
            'cabinets',
            'integration_endpoints',
            'branch_doctor',
            'clinic_doctor',
            'branches',
            'doctors',
            'clinic_city',
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
            $table->string('external_id')->nullable();
            $table->string('crm_provider')->nullable();
            $table->json('crm_settings')->nullable();
            $table->boolean('dashboard_calendar_enabled')->nullable();
            $table->string('integration_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_city', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('city_id');
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
            $table->unsignedBigInteger('city_id');
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
            $table->boolean('is_active')->default(false);
            $table->string('base_url')->nullable();
            $table->json('credentials')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
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
