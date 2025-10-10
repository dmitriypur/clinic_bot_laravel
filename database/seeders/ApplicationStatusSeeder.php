<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ApplicationStatus;

class ApplicationStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'name' => 'Новая',
                'slug' => 'new',
                'type' => 'bid',
                'color' => 'blue',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Записан',
                'slug' => 'scheduled',
                'type' => 'bid',
                'color' => 'green',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Отменен',
                'slug' => 'cancelled',
                'type' => 'bid',
                'color' => 'red',
                'sort_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($statuses as $statusData) {
            ApplicationStatus::updateOrCreate(
                ['slug' => $statusData['slug']],
                $statusData
            );
        }
    }
}
