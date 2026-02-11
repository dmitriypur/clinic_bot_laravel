<?php

namespace Database\Seeders;

use App\Models\ApplicationStatus;
use Illuminate\Database\Seeder;

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
                'name' => 'Записан (старый режим)',
                'slug' => 'scheduled',
                'type' => 'bid',
                'color' => 'green',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Отменён (старый режим)',
                'slug' => 'cancelled',
                'type' => 'bid',
                'color' => 'red',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Отказался',
                'slug' => 'bid_cancelled',
                'type' => 'bid',
                'color' => 'red',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'Записан на приём',
                'slug' => 'appointment',
                'type' => 'appointment',
                'color' => 'green',
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'Создана',
                'slug' => 'appointment_scheduled',
                'type' => 'appointment',
                'color' => 'gray',
                'sort_order' => 11,
                'is_active' => true,
            ],
            [
                'name' => 'Подтверждена',
                'slug' => 'appointment_confirmed',
                'type' => 'appointment',
                'color' => 'blue',
                'sort_order' => 12,
                'is_active' => true,
            ],
            [
                'name' => 'Идёт приём',
                'slug' => 'appointment_in_progress',
                'type' => 'appointment',
                'color' => 'yellow',
                'sort_order' => 13,
                'is_active' => true,
            ],
            [
                'name' => 'Приём проведён',
                'slug' => 'appointment_completed',
                'type' => 'appointment',
                'color' => 'green',
                'sort_order' => 14,
                'is_active' => true,
            ],
            [
                'name' => 'Отменена',
                'slug' => 'appointment_cancelled',
                'type' => 'appointment',
                'color' => 'red',
                'sort_order' => 15,
                'is_active' => true,
            ],
        ];

        foreach ($statuses as $statusData) {
            $status = ApplicationStatus::where('slug', $statusData['slug'])->first();

            if (! $status) {
                // если slug новый, но уже есть запись с таким названием, обновляем её
                $status = ApplicationStatus::where('name', $statusData['name'])->first();
            }

            if ($status) {
                $status->update($statusData);
            } else {
                ApplicationStatus::create($statusData);
            }
        }
    }
}
