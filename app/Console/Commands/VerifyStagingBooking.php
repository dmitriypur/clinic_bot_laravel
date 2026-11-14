<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\BookingOperation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VerifyStagingBooking extends Command
{
    protected $signature = 'booking:verify-staging {authorization-file}';

    protected $description = 'Verify and clean up a local booking through the public staging API';

    private const API = 'https://test.adminzrenie.ru/api/integrations/v1';

    private const CLIENT = 'elevenlabs-test';

    private const PHONE = '+70000000000';

    public function handle(): int
    {
        if (! app()->environment('staging') || config('app.url') !== 'https://test.adminzrenie.ru'
            || DB::connection()->getDatabaseName() !== 'adminzrenie_test') {
            $this->error('The isolated staging application and database are required.');

            return self::FAILURE;
        }

        $file = $this->argument('authorization-file');
        $authorization = is_readable($file) ? trim(file_get_contents($file)) : '';
        if (! preg_match('/\ABearer elevenlabs-test\.[a-f0-9]{64}\z/', $authorization)) {
            $this->error('The staging booking authorization file is missing or invalid.');

            return self::FAILURE;
        }

        $keys = [];
        $marker = 'Staging booking smoke '.Str::uuid();
        try {
            $this->verify($authorization, $marker, $keys);
        } catch (Throwable $exception) {
            // The HTTP payload and headers can contain patient fields or credentials.
            $this->error($exception instanceof RuntimeException && $exception->getCode() === 1
                ? $exception->getMessage() : 'Staging booking verification failed ('.$exception::class.').');

            return self::FAILURE;
        } finally {
            if ($keys !== []) {
                $this->cleanup($keys, $marker);
            }
        }

        $this->info('Public staging API confirmed one local booking, rejected the competing write and replayed the same booking. Smoke records removed.');

        return self::SUCCESS;
    }

    private function verify(string $authorization, string $marker, array &$keys): void
    {
        $api = Http::withHeaders(['Authorization' => $authorization])->acceptJson()->withoutRedirecting()->timeout(15);
        $branches = $api->get(self::API.'/catalog/search', ['type' => 'branch', 'q' => 'Преображенская 38']);
        $branch = $branches->json('data.0');
        $this->require($branches->ok() && data_get($branch, 'city.name') === 'Киров', 'Staging catalogue lookup failed.');

        $tomorrow = CarbonImmutable::tomorrow('Europe/Moscow');
        $filters = [
            'branch_ids' => [$branch['id']], 'date_from' => $tomorrow->toDateString(),
            'date_to' => $tomorrow->addDays(29)->toDateString(), 'birth_date' => '1990-01-01',
        ];
        $search = $api->post(self::API.'/availability/search', $filters);
        $window = collect($search->json('data'))->first(fn (array $item) => str_starts_with($item['id'], 'local:')
            && filled($item['offer_token'] ?? null));
        $this->require($search->ok() && $window, 'No future local offer at the staging branch. Check test shifts and write permissions.');

        $secondSearch = $api->post(self::API.'/availability/search', $filters + ['doctor_ids' => [$window['doctor']['id']]]);
        $second = collect($secondSearch->json('data'))->firstWhere('id', $window['id']);
        $this->require($secondSearch->ok() && filled($second['offer_token'] ?? null), 'Could not obtain a second offer for the same window.');

        $keys = [$window['operation_id'], $second['operation_id']];
        $this->require(Str::isUuid($keys[0]) && Str::isUuid($keys[1]) && $keys[0] !== $keys[1], 'Offers must have distinct operation IDs.');
        $body = [
            'full_name' => 'Проверочный Пациент', 'birth_date' => '1990-01-01', 'phone' => self::PHONE,
            'confirmed' => true, 'privacy_consent' => true, 'comment' => $marker,
        ];
        $responses = Http::pool(fn (Pool $pool) => array_map(fn (array $offer) => $pool->withHeaders(['Authorization' => $authorization])
            ->acceptJson()->withoutRedirecting()->timeout(15)->post(self::API.'/bookings', $body + ['offer_token' => $offer['offer_token']]), [$window, $second]));

        $winner = $responses[0]->created() ? 0 : 1;
        $created = $responses[$winner];
        $rejected = $responses[1 - $winner];
        $bookingId = $created->json('booking_id');
        $this->require($created->created() && $created->json('status') === 'confirmed' && ctype_digit((string) $bookingId)
            && $created->json('operation_id') === $keys[$winner], 'Neither competing request confirmed the expected local operation.');
        $this->require($rejected->conflict() && $rejected->json('code') === 'slot_unavailable'
            && $rejected->json('operation_id') === $keys[1 - $winner], 'The competing local write must be rejected as slot_unavailable.');

        $status = $api->get(self::API.'/operations/'.$keys[$winner]);
        $retry = $api->post(self::API.'/bookings', $body + ['offer_token' => [$window, $second][$winner]['offer_token']]);
        foreach ([$status, $retry] as $response) {
            $this->require($response->ok() && $response->json('status') === 'confirmed'
                && $response->json('operation_id') === $keys[$winner] && $response->json('booking_id') === $bookingId,
                'Status and replay must return the original confirmed operation and booking.');
        }

        $records = $this->applications($marker)->get();
        $record = $records->first();
        $this->require($records->count() === 1 && (string) $record->id === $bookingId
            && $record->branch_id === $branch['id'] && $record->doctor_id === $window['doctor']['id']
            && ! $record->send_to_1c && $record->external_appointment_id === null
            && $record->status?->slug === Application::STATUS_SLUG_APPOINTMENT_CONFIRMED,
            'Expected exactly one confirmed local application in the staging appointment register.');
    }

    private function cleanup(array $keys, string $marker): void
    {
        DB::transaction(function () use ($keys, $marker) {
            BookingOperation::query()->where('client_id', self::CLIENT)->whereIn('operation_key', $keys)->delete();
            $this->applications($marker)->delete();
        });
        Application::clearCalendarCache();
    }

    private function applications(string $marker): \Illuminate\Database\Eloquent\Builder
    {
        return Application::query()->where('submission_source', self::CLIENT)->where('integration_type', Application::INTEGRATION_TYPE_LOCAL)
            ->where('full_name', 'Проверочный Пациент')->where('phone', self::PHONE)->where('integration_payload->comment', $marker);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message, 1);
        }
    }
}
