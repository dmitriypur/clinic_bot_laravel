<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use Database\Seeders\BookingIntegrationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        ])->assertOk()->assertJsonStructure(['data' => [['offer_token', 'offer_expires_at']]]);

        return [
            'offer_token' => $result->json('data.0.offer_token'),
            'full_name' => 'Пациент Тестовый',
            'birth_date' => '1990-01-01',
            'phone' => '+70000000000',
            'confirmed' => true,
            'privacy_consent' => true,
        ];
    }
}
