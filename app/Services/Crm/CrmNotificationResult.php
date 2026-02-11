<?php

namespace App\Services\Crm;

class CrmNotificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?array $response = null,
        public readonly ?string $error = null,
    ) {}
}
