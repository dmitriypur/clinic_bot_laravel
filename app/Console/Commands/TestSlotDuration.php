<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Clinic;
use App\Models\Branch;
use App\Models\DoctorShift;
use App\Services\SlotService;
use Carbon\Carbon;

class TestSlotDuration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:slot-duration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Тестирование функционала длительности слотов';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Тестирование функционала длительности слотов...');
        
        $slotService = new SlotService();
        
        // Тест 1: Создание клиники с длительностью слота 60 минут
        $this->info("\n1. Создание клиники с длительностью слота 60 минут:");
        $clinic = Clinic::create([
            'name' => 'Тестовая клиника',
            'status' => 1,
            'slot_duration' => 60,
        ]);
        $this->line("Клиника создана: {$clinic->name}, длительность слота: {$clinic->getEffectiveSlotDuration()} мин");
        
        // Тест 2: Создание филиала без указания длительности слота
        $this->info("\n2. Создание филиала без указания длительности слота:");
        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => 1, // Предполагаем, что есть город с ID 1
            'name' => 'Тестовый филиал',
            'address' => 'Тестовый адрес',
            'phone' => '+7 (999) 123-45-67',
            'status' => 1,
            // slot_duration не указан
        ]);
        $this->line("Филиал создан: {$branch->name}, эффективная длительность слота: {$branch->getEffectiveSlotDuration()} мин");
        
        // Тест 3: Создание филиала с собственной длительностью слота
        $this->info("\n3. Создание филиала с собственной длительностью слота 30 минут:");
        $branch2 = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => 1,
            'name' => 'Тестовый филиал 2',
            'address' => 'Тестовый адрес 2',
            'phone' => '+7 (999) 123-45-68',
            'status' => 1,
            'slot_duration' => 30,
        ]);
        $this->line("Филиал создан: {$branch2->name}, эффективная длительность слота: {$branch2->getEffectiveSlotDuration()} мин");
        
        // Тест 4: Создание кабинета и смены врача
        $this->info("\n4. Создание кабинета и смены врача:");
        $cabinet = \App\Models\Cabinet::create([
            'branch_id' => $branch->id,
            'name' => 'Тестовый кабинет',
            'status' => 1,
        ]);
        
        $doctor = \App\Models\Doctor::first();
        if (!$doctor) {
            $doctor = \App\Models\Doctor::create([
                'name' => 'Тестовый врач',
                'surname' => 'Тестов',
                'patronymic' => 'Тестович',
                'status' => 1,
            ]);
        }
        
        $shift = DoctorShift::create([
            'doctor_id' => $doctor->id,
            'cabinet_id' => $cabinet->id,
            'start_time' => Carbon::now()->startOfDay()->addHours(9),
            'end_time' => Carbon::now()->startOfDay()->addHours(18),
        ]);
        
        $this->line("Смена создана, эффективная длительность слота: {$shift->getEffectiveSlotDuration()} мин");
        
        // Тест 5: Генерация слотов времени
        $this->info("\n5. Генерация слотов времени для смены:");
        $slots = $shift->getTimeSlots();
        
        $this->line("Сгенерировано слотов: " . count($slots));
        foreach (array_slice($slots, 0, 5) as $slot) {
            $this->line("  - {$slot['formatted']} ({$slot['duration']} мин)");
        }
        if (count($slots) > 5) {
            $this->line("  ... и еще " . (count($slots) - 5) . " слотов");
        }
        
        // Тест 6: Стандартные варианты длительности слотов
        $this->info("\n6. Стандартные варианты длительности слотов:");
        $standardDurations = $slotService->getStandardSlotDurations();
        foreach ($standardDurations as $minutes => $label) {
            $this->line("  - {$minutes} мин: {$label}");
        }
        
        $this->info("\n✅ Тестирование завершено успешно!");
        
        // Очистка тестовых данных
        $this->info("\nОчистка тестовых данных...");
        $shift->delete();
        $cabinet->delete();
        $branch->delete();
        $branch2->delete();
        $clinic->delete();
        $this->line("Тестовые данные удалены.");
    }
}
