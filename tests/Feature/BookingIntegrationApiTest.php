<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\OnecSlot;
use Database\Seeders\BookingIntegrationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const CATALOG = '/api/integrations/v1/catalog/search';

    private const AVAILABILITY = '/api/integrations/v1/availability/search';

    private string $token;

    private string $catalogToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 17:30:00', 'Europe/Moscow'));
        $this->token = 'postman.'.bin2hex(random_bytes(32));
        $this->catalogToken = 'limited.'.bin2hex(random_bytes(32));
        config(['booking-integrations.clients' => [
            'postman' => [
                'token_hash' => hash('sha256', $this->token),
                'scopes' => ['catalog:read', 'availability:read'],
            ],
            'limited' => [
                'token_hash' => hash('sha256', $this->catalogToken),
                'scopes' => ['catalog:read'],
                'branch_ids' => [1],
            ],
        ]]);
    }

    public function test_both_routes_require_a_valid_bearer_and_the_matching_scope(): void
    {
        $catalog = self::CATALOG.'?type=branch&q=Центр';

        $this->getJson($catalog)->assertUnauthorized();
        $this->postJson(self::AVAILABILITY)->assertUnauthorized();
        $this->getJson($catalog, ['Authorization' => 'Bearer postman.'.str_repeat('0', 64)])->assertUnauthorized();
        $this->postJson(self::AVAILABILITY, [], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        $this->postJson(self::AVAILABILITY, [], ['Authorization' => 'Bearer '.$this->catalogToken])->assertForbidden();
        $this->getJson($catalog, ['Authorization' => 'Bearer '.$this->catalogToken])->assertOk();
        $this->search([])->assertOk();
    }

    public function test_catalog_resolves_declined_surname_and_returns_distinct_candidates_with_addresses(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);

        $this->catalog('doctor', 'Видлацкой')
            ->assertOk()->assertJsonPath('meta.ambiguous', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.name', 'Видлацкая Анна')
            ->assertJsonPath('data.0.branches.0.city.name', 'Киров')
            ->assertJsonPath('data.0.branches.0.address', 'ул. Ленина, 1')
            ->assertJsonPath('data.1.branches.0.city.name', 'Москва');

        $this->catalog('doctor', 'ОФТАЛЬМОЛОГ', ['city_id' => 1])
            ->assertOk()->assertJsonCount(3, 'data');
        $this->catalog('branch', 'ленина 1')->assertOk()->assertJsonPath('data.0.id', 1);
        $this->catalog('branch', 'север')->assertOk()->assertJsonPath('data.0.id', 2);
        $this->catalog('city', 'киров')->assertOk()->assertJsonPath('data.0.id', 1);
        $this->catalog('clinic', 'зрение')->assertOk()->assertJsonCount(2, 'data');
        $this->catalog('doctor', 'Видлацкая', ['limit' => 1])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.ambiguous', true)->assertJsonPath('meta.has_more', true);
        $this->getJson(self::CATALOG.'?type=doctor&q=Видлацкая', ['Authorization' => 'Bearer '.$this->catalogToken])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.branches');
    }

    public function test_same_doctor_is_used_for_the_wider_period(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);

        $this->search(['doctor_ids' => [1], 'date_from' => '2026-11-10', 'date_to' => '2026-11-11', 'limit' => 5])
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.schedule_status', 'complete');

        $this->search(['doctor_ids' => [1], 'date_from' => '2026-11-12', 'date_to' => '2026-12-11', 'limit' => 1])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('data.0.doctor.id', 1)
            ->assertJsonPath('data.0.branch.id', 2)
            ->assertJsonPath('data.0.branch.address', 'ул. Московская, 2')
            ->assertJsonPath('data.0.starts_at', '2026-11-12T15:00:00+03:00')
            ->assertJsonPath('data.0.ends_at', '2026-11-12T15:30:00+03:00')
            ->assertJsonPath('data.0.timezone', 'Europe/Moscow');
    }

    public function test_child_search_excludes_moscow_occupied_past_and_ineligible_slots(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);

        $this->search([
            'city_id' => 1, 'specialty' => 'офтальмолог', 'patient_age' => 7, 'time_from' => '15:01',
            'date_from' => '2026-11-09', 'date_to' => '2026-11-11',
        ])->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.schedule_status', 'complete')
            ->assertJsonPath('data.0.starts_at', '2026-11-11T16:30:00+03:00')
            ->assertJsonPath('data.1.starts_at', '2026-11-11T17:00:00+03:00')
            ->assertJsonPath('data.2.starts_at', '2026-11-11T19:00:00+03:00')
            ->assertJsonMissing(['name' => 'Москва'])->assertJsonMissing(['name' => 'Тестовый пациент']);
    }

    #[DataProvider('independentFilters')]
    public function test_independent_and_combined_filters(array $filters, array $expectedDoctors): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);

        $response = $this->search($filters + ['date_from' => '2026-11-11'])->assertOk();
        $this->assertSame($expectedDoctors, collect($response->json('data'))->pluck('doctor.id')->all());
    }

    public static function independentFilters(): array
    {
        return [
            'clinic' => [['clinic_ids' => [2]], [2, 5]],
            'branches' => [['branch_ids' => [2, 3]], [2, 5, 3]],
            'one branch' => [['branch_ids' => [2]], [3]],
            'doctor' => [['doctor_ids' => [5]], [5]],
            'doctors' => [['doctor_ids' => [2, 5]], [2, 5]],
            'date' => [['date_from' => '2026-11-12'], [1, 3, 1]],
            'time from' => [['time_from' => '17:00'], [3, 3]],
            'time to' => [['time_to' => '15:00'], [3, 3]],
            'age' => [['patient_age' => 7], [3, 3, 5, 4, 3, 3, 3]],
            'birthday during the period' => [[
                'birth_date' => '2018-11-12', 'doctor_ids' => [3], 'date_to' => '2026-11-12',
            ], [3, 3, 3, 3, 3]],
            'combined' => [[
                'city_id' => 1, 'clinic_ids' => [1], 'branch_ids' => [1, 2], 'doctor_ids' => [1, 3, 4],
                'specialty' => 'офтальмолог', 'patient_age' => 7, 'time_from' => '15:00', 'time_to' => '17:00',
            ], [3, 3, 3]],
        ];
    }

    public function test_incompatible_filters_are_rejected(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);

        foreach ([
            [['doctor_ids' => []], 'doctor_ids'],
            [['branch_ids' => [1, 3], 'clinic_ids' => [1]], 'branch_ids'],
            [['branch_ids' => [3], 'city_id' => 1], 'branch_ids'],
            [['branch_ids' => [999]], 'branch_ids'],
            [['date_from' => '2026-11-11', 'date_to' => '2026-11-10'], 'date_to'],
            [['date_from' => '2026-11-11', 'date_to' => '2026-12-12'], 'date_to'],
            [['time_from' => '18:00', 'time_to' => '15:00'], 'time_to'],
            [['patient_age' => 7, 'birth_date' => '2019-11-11'], 'birth_date'],
            [['price_max' => 3000], 'price_max'],
        ] as [$filters, $field]) {
            $this->search($filters)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_client_scope_and_catalog_activity_apply_to_availability(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);
        config(['booking-integrations.clients.postman.branch_ids' => [1, 2]]);
        Doctor::findOrFail(4)->update(['status' => 0]);
        Branch::findOrFail(1)->doctors()->detach(6);
        Branch::findOrFail(2)->update(['status' => 0]);

        $this->search(['date_from' => '2026-11-11'])->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonMissing(['name' => 'Москва'])->assertJsonMissing(['name' => 'Север']);
        $this->search(['branch_ids' => [3]])->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
        $this->catalog('doctor', 'Иванов')->assertOk()->assertJsonCount(0, 'data');
        $this->catalog('doctor', 'Сидорова')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_branch_timezone_is_used_for_filtering_and_display(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);
        Branch::findOrFail(2)->update(['timezone' => 'Asia/Yekaterinburg']);

        $this->search(['branch_ids' => [2], 'date_from' => '2026-11-11', 'time_from' => '18:30', 'time_to' => '18:30'])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-11-11T18:30:00+05:00')
            ->assertJsonPath('data.0.timezone', 'Asia/Yekaterinburg');
    }

    public function test_onec_uses_existing_local_storage_time_and_reports_unverified_days(): void
    {
        $this->seed(BookingIntegrationDemoSeeder::class);
        Clinic::findOrFail(1)->update(['integration_mode' => 'onec_push']);
        Branch::findOrFail(2)->update(['integration_mode' => 'local']);
        Doctor::findOrFail(3)->update(['specialty' => null]);

        $base = [
            'clinic_id' => 1, 'branch_id' => 1, 'doctor_id' => 3,
            'source_payload' => ['doctor' => ['espec' => 'Детский офтальмолог'], 'private' => 'hidden'],
            'synced_at' => now(),
        ];
        foreach ([
            ['2026-11-10 16:00', 'free', now()],
            ['2026-11-11 15:00', 'free', now()],
            ['2026-11-11 16:00', 'free', now()],
            ['2026-11-11 16:00', 'booked', now()],
            ['2026-11-11 17:00', 'blocked', now()],
            ['2026-11-12 16:00', 'free', now()->subDays(2)],
        ] as $index => [$start, $status, $syncedAt]) {
            OnecSlot::create([
                'external_slot_id' => 'onec-demo-'.$index,
                'start_at' => $start, 'end_at' => Carbon::parse($start)->addMinutes(30),
                'status' => $status, 'synced_at' => $syncedAt,
            ] + $base);
        }

        $this->catalog('doctor', 'офтальмолог', ['city_id' => 1])
            ->assertOk()->assertJsonCount(3, 'data');

        $this->search([
            'branch_ids' => [1], 'doctor_ids' => [3], 'specialty' => 'офтальмолог',
            'date_from' => '2026-11-10', 'date_to' => '2026-11-13', 'time_from' => '15:00',
        ])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-11-11T15:00:00+03:00')
            ->assertJsonPath('data.0.doctor.specialty', 'Детский офтальмолог')
            ->assertJsonPath('meta.schedule_status', 'partial')
            ->assertJsonPath('meta.unverified_branches.0.dates', ['2026-11-12', '2026-11-13'])
            ->assertJsonMissing(['private' => 'hidden']);

        $this->search(['branch_ids' => [1], 'doctor_ids' => [3], 'date_from' => '2026-11-12'])
            ->assertServiceUnavailable()->assertJsonPath('meta.schedule_status', 'schedule_unavailable');
        $this->search(['branch_ids' => [1, 2], 'doctor_ids' => [3], 'date_from' => '2026-11-11'])
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.schedule_status', 'complete');
    }

    private function catalog(string $type, string $query, array $filters = []): TestResponse
    {
        return $this->getJson(self::CATALOG.'?'.http_build_query(['type' => $type, 'q' => $query] + $filters), [
            'Authorization' => 'Bearer '.$this->token,
        ]);
    }

    private function search(array $filters): TestResponse
    {
        return $this->postJson(self::AVAILABILITY, $filters, ['Authorization' => 'Bearer '.$this->token]);
    }
}
