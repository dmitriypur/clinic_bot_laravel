<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('applications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $existingIndexes = collect();

        if ($driver === 'mysql') {
            $existingIndexes = collect(DB::select('SHOW INDEX FROM applications'))
                ->pluck('Key_name')
                ->unique();
        }

        Schema::table('applications', function (Blueprint $table) use ($existingIndexes) {
            if (! $existingIndexes->contains('idx_appointment_datetime')) {
                $table->index('appointment_datetime', 'idx_appointment_datetime');
            }
            if (! $existingIndexes->contains('idx_cabinet_datetime')) {
                $table->index(['cabinet_id', 'appointment_datetime'], 'idx_cabinet_datetime');
            }
            if (! $existingIndexes->contains('idx_doctor_datetime')) {
                $table->index(['doctor_id', 'appointment_datetime'], 'idx_doctor_datetime');
            }
            if (! $existingIndexes->contains('idx_clinic_datetime')) {
                $table->index(['clinic_id', 'appointment_datetime'], 'idx_clinic_datetime');
            }
            if (! $existingIndexes->contains('idx_branch_datetime')) {
                $table->index(['branch_id', 'appointment_datetime'], 'idx_branch_datetime');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('applications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $indexes = collect();

        if ($driver === 'mysql') {
            $indexes = collect(DB::select('SHOW INDEX FROM applications'));
        }

        Schema::table('applications', function (Blueprint $table) use ($indexes, $driver) {
            $shouldDrop = function (string $indexName) use ($indexes, $driver): bool {
                if ($driver === 'mysql') {
                    return $indexes->where('Key_name', $indexName)->isNotEmpty();
                }

                return true;
            };

            $safeDrop = function (callable $callback): void {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    // Индекса может не существовать в конкретной БД
                }
            };

            if ($shouldDrop('idx_appointment_datetime')) {
                $safeDrop(fn () => $table->dropIndex('idx_appointment_datetime'));
            }

            if ($shouldDrop('idx_cabinet_datetime')) {
                $safeDrop(fn () => $table->dropIndex('idx_cabinet_datetime'));
            }

            if ($shouldDrop('idx_doctor_datetime')) {
                $safeDrop(fn () => $table->dropIndex('idx_doctor_datetime'));
            }

            if ($shouldDrop('idx_clinic_datetime')) {
                $safeDrop(fn () => $table->dropIndex('idx_clinic_datetime'));
            }

            if ($shouldDrop('idx_branch_datetime')) {
                $safeDrop(fn () => $table->dropIndex('idx_branch_datetime'));
            }
        });
    }
};
