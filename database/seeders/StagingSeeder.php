<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class StagingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('staging') || DB::connection()->getDatabaseName() !== 'adminzrenie_test') {
            throw new LogicException('Use the isolated staging database for staging data.');
        }

        $this->call(ApplicationStatusSeeder::class);
    }
}
