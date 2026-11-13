<?php

namespace App\Services\Booking;

use App\Models\Branch;
use App\Models\OnecSlot;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class BookingOffer
{
    public function allowsBranch(int $branchId, ?array $allowedBranchIds): bool
    {
        return $allowedBranchIds !== null && in_array($branchId, $allowedBranchIds, true)
            && in_array($branchId, config('booking-integrations.write_branch_ids'), true);
    }

    public function attach(array $result, string $clientId, ?array $allowedBranchIds): array
    {
        $expiresAt = now()->addMinutes(10);
        foreach ($result['data'] as &$window) {
            if (! str_starts_with($window['id'], 'onec:')
                || ! $this->allowsBranch($window['branch']['id'], $allowedBranchIds)) {
                continue;
            }

            $window['offer_expires_at'] = $expiresAt->toIso8601String();
            $window['offer_token'] = Crypt::encryptString(json_encode([
                'purpose' => 'booking_offer',
                'client_id' => $clientId,
                'slot' => $window['id'],
                'branch' => $window['branch']['id'],
                'doctor' => $window['doctor']['id'],
                'start' => $window['starts_at'],
                'end' => $window['ends_at'],
                'expires' => $expiresAt->timestamp,
            ], JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    public function read(string $token, string $clientId, ?array $allowedBranchIds): array
    {
        try {
            $offer = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            abort(404);
        }

        abort_unless(($offer['purpose'] ?? null) === 'booking_offer'
            && $offer['client_id'] === $clientId
            && $this->allowsBranch($offer['branch'], $allowedBranchIds), 404);

        return $offer;
    }

    public function slot(array $offer): OnecSlot
    {
        return OnecSlot::query()->where('branch_id', $offer['branch'])
            ->where('doctor_id', $offer['doctor'])->findOrFail(substr($offer['slot'], 5));
    }

    public function matchesWindow(array $offer, OnecSlot $slot, Branch $branch): bool
    {
        return (int) $slot->clinic_id === $branch->clinic_id
            && $slot->start_at?->setTimezone($branch->bookingTimezone())->toIso8601String() === $offer['start']
            && $slot->end_at?->setTimezone($branch->bookingTimezone())->toIso8601String() === $offer['end'];
    }
}
