<?php

return [
    'clients' => json_decode((string) env('BOOKING_INTEGRATION_CLIENTS', '{}'), true) ?: [],
    'write_branch_ids' => json_decode((string) env('BOOKING_WRITE_BRANCH_IDS', '[]'), true) ?: [],
    'onec_max_age_minutes' => (int) env('BOOKING_ONEC_MAX_AGE_MINUTES', 1440),
    'onec_max_age_by_branch' => json_decode((string) env('BOOKING_ONEC_MAX_AGE_BY_BRANCH', '{}'), true) ?: [],
];
