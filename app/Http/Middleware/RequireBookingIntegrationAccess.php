<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireBookingIntegrationAccess
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $header = $request->headers->get('Authorization', '');
        if (! preg_match('/\ABearer (([a-z0-9_-]{1,64})\.[a-f0-9]{64})\z/i', $header, $matches)) {
            return $this->unauthorized();
        }

        [, $token, $clientId] = $matches;
        $client = config('booking-integrations.clients')[$clientId] ?? [];
        $hash = $client['token_hash'] ?? null;
        if (! is_string($hash) || ! hash_equals($hash, hash('sha256', $token))) {
            return $this->unauthorized();
        }

        $scopes = $client['scopes'] ?? [];
        $branchIds = $client['branch_ids'] ?? null;
        if (! is_array($scopes) || ! in_array($scope, $scopes, true)
            || (array_key_exists('branch_ids', $client) && ! $this->validBranchIds($branchIds))) {
            return response()->json(['code' => 'insufficient_scope', 'message' => 'Недостаточно прав для запроса.'], 403);
        }

        $request->attributes->set('booking_integration_branch_ids', $branchIds);
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }

    private function validBranchIds(mixed $ids): bool
    {
        return is_array($ids) && array_is_list($ids)
            && array_filter($ids, fn ($id) => is_int($id) && $id > 0) === $ids;
    }

    private function unauthorized(): Response
    {
        return response()->json(['code' => 'unauthenticated', 'message' => 'Требуется действующий ключ доступа.'], 401, [
            'WWW-Authenticate' => 'Bearer',
        ]);
    }
}
