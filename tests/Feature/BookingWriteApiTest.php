<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use Database\Seeders\BookingIntegrationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingWriteApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/integrations/v1/';

    private string $token;

    private string $otherToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Europe/Moscow'));
        $this->seed(BookingIntegrationDemoSeeder::class);
        $this->token = 'postman.'.bin2hex(random_bytes(32));
        $this->otherToken = 'other.'.bin2hex(random_bytes(32));
        $scopes = ['availability:read', 'bookings:write', 'operations:read'];
        config([
            'booking-integrations.write_branch_ids' => [3],
            'booking-integrations.clients' => [
                'postman' => ['token_hash' => hash('sha256', $this->token), 'scopes' => $scopes, 'branch_ids' => [3]],
                'other' => ['token_hash' => hash('sha256', $this->otherToken), 'scopes' => $scopes, 'branch_ids' => [3]],
            ],
        ]);

        Branch::findOrFail(3)->update(['integration_mode' => 'onec_push']);
        Doctor::findOrFail(2)->update(['external_id' => 'demo-doctor']);
        IntegrationEndpoint::create([
            'branch_id' => 3, 'type' => IntegrationEndpoint::TYPE_ONEC, 'name' => 'Demo',
            'base_url' => 'http://127.0.0.1:8012', 'is_active' => true,
        ]);
        OnecSlot::create([
            'clinic_id' => 2, 'branch_id' => 3, 'doctor_id' => 2, 'external_slot_id' => 'demo-slot',
            'start_at' => '2026-11-11 16:00:00', 'end_at' => '2026-11-11 16:30:00',
            'status' => OnecSlot::STATUS_FREE, 'synced_at' => now(),
        ]);
        Http::preventStrayRequests();
    }

    public function test_confirmation_status_and_retry_create_one_booking(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'claim_id' => 'demo-claim'], 201)]);
        $body = $this->body();
        $key = (string) Str::uuid();

        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', $body)
            ->assertCreated()->assertJsonPath('status', 'confirmed')->assertJsonPath('operation_id', $key);
        $this->getJson(self::API.'operations/'.$key)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->travel(11)->minutes();
        $this->postJson(self::API.'bookings', $body)->assertOk()->assertJsonPath('operation_id', $key);
        $this->postJson(self::API.'bookings', array_replace($body, ['phone' => '+70000000001']))
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->withToken($this->otherToken)->getJson(self::API.'operations/'.$key)->assertNotFound();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['slot_id'] === 'demo-slot'
            && $request['appointment_id'] !== $key
            && $request['appointment_id'] === DB::table('booking_operations')->value('onec_request_id'));
        $this->assertSame(1, Application::where('external_appointment_id', 'demo-claim')->count());
        $this->assertDatabaseHas('onec_slots', ['external_slot_id' => 'demo-slot', 'status' => 'booked']);
    }

    public function test_offer_supplies_stable_operation_id_without_a_header(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'claim_id' => 'voice-claim'], 201)]);
        $body = $this->body();
        $operationId = $body['operation_id'];
        unset($body['operation_id']);

        $this->postJson(self::API.'bookings', $body)
            ->assertCreated()->assertJsonPath('operation_id', $operationId);
        $this->getJson(self::API.'operations/'.$operationId)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson(self::API.'bookings', $body)->assertOk()->assertJsonPath('operation_id', $operationId);
        Http::assertSentCount(1);
    }

    public function test_local_booking_confirms_and_replays_without_dispatching_integrations(): void
    {
        Http::fake();
        Queue::fake();
        $window = $this->localWindow();
        $body = $this->localBody($window);
        $key = $window['operation_id'];

        $this->postJson(self::API.'bookings', array_replace($body, ['privacy_consent' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('privacy_consent');
        $bookingId = $this->postJson(self::API.'bookings', $body)->assertCreated()
            ->assertJsonPath('operation_id', $key)->assertJsonPath('status', 'confirmed')->json('booking_id');
        $this->getJson(self::API.'operations/'.$key)->assertOk()->assertJsonPath('booking_id', $bookingId);
        $this->travel(11)->minutes();
        $this->postJson(self::API.'bookings', $body)->assertOk()->assertJsonPath('booking_id', $bookingId);
        $this->postJson(self::API.'bookings', array_replace($body, ['phone' => '+70000000001']))
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseHas('applications', [
            'id' => $bookingId, 'branch_id' => 2, 'doctor_id' => 1, 'submission_source' => 'postman',
            'integration_type' => 'local', 'send_to_1c' => false, 'appointment_datetime' => '2026-11-12 12:00:00',
            'full_name' => 'Пациент Тестовый', 'phone' => '+70000000000',
        ]);
        $this->assertSame(1, Application::where('submission_source', 'postman')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_local_offer_is_rejected_if_expired_or_consumed_by_another_operation(): void
    {
        $first = $this->localBody($this->localWindow());
        $second = $this->localBody($this->localWindow());
        $expired = $this->localBody($this->localWindow());
        $this->travel(11)->minutes();
        $this->postJson(self::API.'bookings', $expired)->assertConflict()->assertJsonPath('code', 'offer_expired');
        $this->travel(-11)->minutes();

        $this->postJson(self::API.'bookings', $first)->assertCreated();
        $this->postJson(self::API.'bookings', $second)->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        $this->assertSame(1, Application::where('submission_source', 'postman')->count());
    }

    public function test_local_offer_requires_the_same_client_and_branch_permissions(): void
    {
        $window = $this->localWindow();
        $body = $this->localBody($window);
        $this->withToken($this->otherToken)->postJson(self::API.'bookings', $body)->assertNotFound();

        config(['booking-integrations.write_branch_ids' => [3]]);
        $this->withToken($this->token)->postJson(self::API.'bookings', $body)->assertNotFound();
        config(['booking-integrations.write_branch_ids' => [2, 3]]);
        $this->postJson(self::API.'bookings', $body)->assertCreated();
        $statusUrl = self::API.'operations/'.$window['operation_id'];
        $this->withToken($this->otherToken)->getJson($statusUrl)->assertNotFound();
        config(['booking-integrations.clients.postman.branch_ids' => [3]]);
        $this->withToken($this->token)->getJson($statusUrl)->assertNotFound();
    }

    public function test_local_offer_is_rejected_when_the_shift_was_removed(): void
    {
        $window = $this->localWindow();
        [, $shiftId] = explode(':', $window['id']);
        DoctorShift::findOrFail($shiftId)->delete();

        $this->postJson(self::API.'bookings', $this->localBody($window))->assertConflict()
            ->assertJsonPath('code', 'slot_unavailable');
    }

    public function test_widget_and_integration_share_local_occupancy(): void
    {
        $window = $this->localWindow();
        [, $shiftId] = explode(':', $window['id']);
        $shift = DoctorShift::findOrFail($shiftId);
        $widget = [
            'city_id' => 1, 'branch_id' => 2, 'doctor_id' => 1, 'cabinet_id' => $shift->cabinet_id,
            'appointment_datetime' => '2026-11-12 15:00', 'full_name' => 'Пациент Виджета', 'phone' => '70000000000',
        ];
        $this->postJson('/api/v1/applications', $widget)->assertCreated();
        $this->postJson(self::API.'bookings', $this->localBody($window))->assertConflict()
            ->assertJsonPath('code', 'slot_unavailable');

        Application::where('full_name', 'Пациент Виджета')->delete();
        $this->postJson(self::API.'bookings', $this->localBody($this->localWindow()))->assertCreated();
        $this->postJson('/api/v1/applications', $widget)->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_datetime');
    }

    private function localWindow(): array
    {
        config([
            'booking-integrations.write_branch_ids' => [2, 3],
            'booking-integrations.clients.postman.branch_ids' => [2, 3],
            'booking-integrations.clients.other.branch_ids' => [2, 3],
        ]);

        return $this->withToken($this->token)->postJson(self::API.'availability/search', [
            'branch_ids' => [2], 'doctor_ids' => [1], 'date_from' => '2026-11-12',
        ])->assertOk()->assertJsonStructure(['data' => [['offer_token', 'offer_expires_at', 'operation_id']]])->json('data.0');
    }

    private function localBody(array $window): array
    {
        return [
            'offer_token' => $window['offer_token'], 'full_name' => 'Пациент Тестовый',
            'birth_date' => '2016-01-01', 'phone' => '+70000000000',
            'confirmed' => true, 'privacy_consent' => true, 'comment' => 'ТЕСТ. Не связываться.',
        ];
    }

    public function test_slot_taken_after_search_is_rejected_before_sending(): void
    {
        Http::fake();
        $body = $this->body();
        OnecSlot::where('external_slot_id', 'demo-slot')->update(['status' => 'booked']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::API.'bookings', $body)
            ->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        Http::assertNothingSent();
    }

    public function test_explicit_conflict_from_source_allows_choosing_another_slot(): void
    {
        Http::fake(['*' => Http::response(['code' => 'slot_unavailable'], 409)]);
        $body = $this->body();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', $body)
            ->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        $this->getJson(self::API.'operations/'.$key)->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(0, Application::where('integration_type', 'onec')->count());
    }

    public function test_ambiguous_source_response_keeps_operation_and_blocks_another_send(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $body = $this->body();
        $key = (string) Str::uuid();

        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', $body)
            ->assertAccepted()->assertJsonPath('status', 'unknown');
        $this->getJson(self::API.'operations/'.$key)->assertAccepted()->assertJsonPath('status', 'unknown');
        $this->postJson(self::API.'bookings', $body)->assertAccepted();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::API.'bookings', $body)
            ->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        Http::assertSentCount(1);
        $this->assertSame(0, Application::where('integration_type', 'onec')->count());
    }

    public function test_transport_failure_and_overlapping_slot_keep_the_same_unresolved_operation(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $body = $this->body();
        $slot = OnecSlot::where('external_slot_id', 'demo-slot')->firstOrFail();
        OnecSlot::create(array_replace($slot->getAttributes(), [
            'external_slot_id' => 'overlap',
            'start_at' => '2026-11-11 16:15:00',
            'end_at' => '2026-11-11 16:45:00',
        ]));
        $overlap = $this->body('16:15');

        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', $body)
            ->assertAccepted()->assertJsonPath('status', 'unknown');
        $this->getJson(self::API.'operations/'.$key)->assertAccepted()->assertJsonPath('operation_id', $key);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::API.'bookings', $overlap)
            ->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        $this->assertDatabaseMissing('applications', ['integration_type' => 'onec']);
    }

    public function test_missing_authorization_or_confirmation_cannot_create_a_booking(): void
    {
        $this->postJson(self::API.'bookings')->assertUnauthorized();
        $body = $this->body();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', array_replace($body, ['confirmed' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->withToken($this->otherToken)->postJson(self::API.'bookings', $body)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_production_booking_requires_explicit_branch_access_and_sends_test_comment(): void
    {
        $this->app->instance('env', 'production');
        Http::fake(['*' => Http::response(['status' => 'success', 'claim_id' => 'demo-claim'], 201)]);
        $body = $this->body() + ['comment' => 'ТЕСТ. Не связываться.'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid());

        config(['booking-integrations.write_branch_ids' => []]);
        $this->postJson(self::API.'bookings', $body)->assertNotFound();
        Http::assertNothingSent();

        config(['booking-integrations.write_branch_ids' => [3]]);
        $client = config('booking-integrations.clients.postman');
        unset($client['branch_ids']);
        config(['booking-integrations.clients.postman' => $client]);
        $this->postJson(self::API.'bookings', $body)->assertNotFound();
        Http::assertNothingSent();

        config(['booking-integrations.clients.postman.branch_ids' => [3]]);
        $this->postJson(self::API.'bookings', $body)->assertCreated();
        Http::assertSent(fn ($request) => $request['comment'] === 'ТЕСТ. Не связываться.');
    }

    public function test_legacy_manual_booking_confirms_claim_with_body_status_204(): void
    {
        IntegrationEndpoint::where('branch_id', 3)->firstOrFail()->update([
            'credentials' => ['manual_booking_path' => 'events?action=newrecord'],
        ]);
        Http::fake(['*' => Http::response(['status_code' => 204, 'claim_id' => 'demo-legacy-claim'], 200)]);

        $body = $this->body();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', $body)
            ->assertCreated()->assertJsonPath('status', 'confirmed');
        $this->assertDatabaseHas('applications', ['external_appointment_id' => 'demo-legacy-claim']);
    }

    public function test_legacy_manual_booking_returns_explicit_rejection(): void
    {
        IntegrationEndpoint::where('branch_id', 3)->firstOrFail()->update([
            'credentials' => ['manual_booking_path' => 'events?action=newrecord'],
        ]);
        Http::fake(['*' => Http::response(['status' => 'fail', 'status_code' => 200], 200)]);

        $body = $this->body();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'bookings', $body)
            ->assertUnprocessable()->assertJsonPath('status', 'rejected')->assertJsonPath('code', 'source_rejected');
        $this->getJson(self::API.'operations/'.$key)->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseMissing('applications', ['integration_type' => 'onec']);
    }

    private function body(string $time = '16:00'): array
    {
        $result = $this->withToken($this->token)->postJson(self::API.'availability/search', [
            'branch_ids' => [3], 'doctor_ids' => [2], 'date_from' => '2026-11-11',
            'time_from' => $time, 'time_to' => $time,
        ])->assertOk()->assertJsonStructure(['data' => [['offer_token', 'offer_expires_at', 'operation_id']]]);

        return [
            'offer_token' => $result->json('data.0.offer_token'),
            'operation_id' => $result->json('data.0.operation_id'),
            'full_name' => 'Пациент Тестовый',
            'birth_date' => '1990-01-01',
            'phone' => '+70000000000',
            'confirmed' => true,
            'privacy_consent' => true,
        ];
    }
}
