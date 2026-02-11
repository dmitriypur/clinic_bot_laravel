<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\IntegrationEndpoint;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class IntegrationStatusWidget extends Widget
{
    protected static string $view = 'filament.widgets.integration-status-widget';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('super_admin');
    }

    protected function getViewData(): array
    {
        return Cache::remember('filament.integration-status-widget', now()->addMinutes(1), function () {
            /** @var Collection<int, IntegrationEndpoint> $endpoints */
            $endpoints = IntegrationEndpoint::query()
                ->where('type', IntegrationEndpoint::TYPE_ONEC)
                ->with(['clinic', 'branch'])
                ->get();

            $failed = $endpoints->filter(fn (IntegrationEndpoint $endpoint) => filled($endpoint->last_error_at));
            $stale = $endpoints->filter(fn (IntegrationEndpoint $endpoint) => ! $endpoint->last_success_at || $endpoint->last_success_at->lt(now()->subHours(2)));

            return [
                'total' => $endpoints->count(),
                'failed' => $failed,
                'stale' => $stale,
                'endpoints' => $endpoints,
            ];
        });
    }
}
