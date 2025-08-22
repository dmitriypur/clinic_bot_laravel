<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cities = [
            ['name' => 'Москва', 'status' => 1],
            ['name' => 'Санкт-Петербург', 'status' => 1],
            ['name' => 'Новосибирск', 'status' => 1],
            ['name' => 'Екатеринбург', 'status' => 1],
            ['name' => 'Казань', 'status' => 1],
            ['name' => 'Нижний Новгород', 'status' => 1],
            ['name' => 'Красноярск', 'status' => 1],
            ['name' => 'Челябинск', 'status' => 1],
            ['name' => 'Самара', 'status' => 1],
            ['name' => 'Уфа', 'status' => 1],
        ];

        foreach ($cities as $city) {
            City::create($city);
        }
    }
}