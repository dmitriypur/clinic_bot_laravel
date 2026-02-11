<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Providers;

use App\Modules\OnecSync\Contracts\CancellationConflictResolver;
use App\Modules\OnecSync\Contracts\CellsPayloadSyncFeature;
use App\Modules\OnecSync\Services\CancellationConflictResolverService;
use App\Modules\OnecSync\Services\CellsPayloadSyncService;
use App\Modules\OnecSync\Services\NullCancellationConflictResolver;
use App\Modules\OnecSync\Services\NullCellsPayloadSyncFeature;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

/**
 * Сервис-провайдер модуля. Регистрирует конкретные реализации контрактов
 * или их заглушки, если синхронизация временно отключена.
 */
class OnecSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CellsPayloadSyncFeature::class, function ($app) {
            /** @var Repository $config */
            $config = $app->make(Repository::class);
            $isEnabled = (bool) $config->get('onec-sync.enabled', true);

            if (! $isEnabled) {
                // Модуль выключен — возвращаем Null Object, чтобы потребители не падали.
                return new NullCellsPayloadSyncFeature;
            }

            return new CellsPayloadSyncService(
                $app->make(DatabaseManager::class),
                true,
                (bool) $config->get('onec-sync.cells.auto_delete_on_free', true),
            );
        });

        $this->app->singleton(CancellationConflictResolver::class, function ($app) {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            if (! (bool) $config->get('onec-sync.enabled', true)) {
                return new NullCancellationConflictResolver;
            }

            return new CancellationConflictResolverService;
        });
    }
}
