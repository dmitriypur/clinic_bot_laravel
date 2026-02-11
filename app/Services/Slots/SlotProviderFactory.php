<?php

namespace App\Services\Slots;

use App\Models\Clinic;
use App\Services\CalendarFilterService;

class SlotProviderFactory
{
    public function __construct(
        private readonly CalendarFilterService $filterService,
    ) {}

    public function make(Clinic $clinic): SlotProviderInterface
    {
        if ($clinic->isOnecPushMode()) {
            return new OneCSlotProvider($clinic, $this->filterService);
        }

        return new LocalSlotProvider($clinic, $this->filterService);
    }
}
