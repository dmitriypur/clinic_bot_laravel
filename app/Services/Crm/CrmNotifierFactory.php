<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\Factory as HttpFactory;

class CrmNotifierFactory
{
    public function __construct(private readonly HttpFactory $http) {}

    public function make(string $provider): ?CrmNotifierInterface
    {
        return match ($provider) {
            'bitrix24' => new Bitrix24Notifier($this->http),
            'onec_crm' => new OneCNotifier($this->http),
            'albato' => new AlbatoNotifier($this->http),
            'amo_crm' => new AmoCrmNotifier($this->http),
            default => null,
        };
    }
}
