<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\Factory as HttpFactory;

abstract class AbstractHttpNotifier implements CrmNotifierInterface
{
    public function __construct(
        protected readonly HttpFactory $http,
    ) {}

    protected function preparePayload(array $payload): array
    {
        return $payload;
    }
}
