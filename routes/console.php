<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

foreach (config('booking-monitoring.run_times', ['07:00', '12:00', '17:00']) as $runTime) {
    Schedule::command('booking:check-availability')
        ->dailyAt($runTime)
        ->timezone(config('app.timezone', 'Europe/Moscow'))
        ->withoutOverlapping();
}
