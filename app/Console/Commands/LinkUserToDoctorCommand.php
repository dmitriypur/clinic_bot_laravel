<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Doctor;
use Illuminate\Console\Command;

class LinkUserToDoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctor:link-user {user_id?} {doctor_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Привязать пользователя с ролью doctor к врачу';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('user_id');
        $doctorId = $this->argument('doctor_id');

        // Если аргументы не переданы, показываем интерактивное меню
        if (!$userId) {
            $users = User::whereHas('roles', function ($query) {
                $query->where('name', 'doctor');
            })->get();

            if ($users->isEmpty()) {
                $this->error('Не найдено пользователей с ролью doctor');
                return 1;
            }

            $userOptions = $users->pluck('name', 'id')->toArray();
            $userId = $this->choice('Выберите пользователя:', $userOptions);
        }

        if (!$doctorId) {
            $doctors = Doctor::all();

            if ($doctors->isEmpty()) {
                $this->error('Не найдено врачей в системе');
                return 1;
            }

            $doctorOptions = $doctors->mapWithKeys(function ($doctor) {
                return [$doctor->id => $doctor->full_name];
            })->toArray();

            $doctorId = $this->choice('Выберите врача:', $doctorOptions);
        }

        // Проверяем существование пользователя и врача
        $user = User::find($userId);
        if (!$user) {
            $this->error("Пользователь с ID {$userId} не найден");
            return 1;
        }

        if (!$user->hasRole('doctor')) {
            $this->error("Пользователь {$user->name} не имеет роль doctor");
            return 1;
        }

        $doctor = Doctor::find($doctorId);
        if (!$doctor) {
            $this->error("Врач с ID {$doctorId} не найден");
            return 1;
        }

        // Привязываем
        $user->doctor_id = $doctorId;
        $user->save();

        $this->info("Пользователь {$user->name} успешно привязан к врачу {$doctor->full_name}");
        
        return 0;
    }
}
