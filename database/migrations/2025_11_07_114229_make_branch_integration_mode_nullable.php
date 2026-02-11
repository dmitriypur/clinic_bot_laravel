<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE branches MODIFY integration_mode VARCHAR(255) NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE branches ALTER COLUMN integration_mode DROP DEFAULT');
            DB::statement('ALTER TABLE branches ALTER COLUMN integration_mode DROP NOT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('ALTER TABLE branches RENAME COLUMN integration_mode TO integration_mode_old');

            Schema::table('branches', function (Blueprint $table) {
                $table->string('integration_mode')->nullable();
            });

            DB::statement('UPDATE branches SET integration_mode = integration_mode_old');

            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('integration_mode_old');
            });

            return;
        }

        // Fallback: attempt to drop NOT NULL constraint
        DB::statement('ALTER TABLE branches ALTER COLUMN integration_mode DROP NOT NULL');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE branches MODIFY integration_mode VARCHAR(255) NOT NULL DEFAULT 'local'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("UPDATE branches SET integration_mode = 'local' WHERE integration_mode IS NULL");
            DB::statement("ALTER TABLE branches ALTER COLUMN integration_mode SET DEFAULT 'local'");
            DB::statement('ALTER TABLE branches ALTER COLUMN integration_mode SET NOT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('ALTER TABLE branches RENAME COLUMN integration_mode TO integration_mode_new');

            Schema::table('branches', function (Blueprint $table) {
                $table->string('integration_mode')->default('local');
            });

            DB::statement("UPDATE branches SET integration_mode = COALESCE(integration_mode_new, 'local')");

            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('integration_mode_new');
            });

            return;
        }

        DB::statement("UPDATE branches SET integration_mode = 'local' WHERE integration_mode IS NULL");
        DB::statement('ALTER TABLE branches ALTER COLUMN integration_mode SET NOT NULL');
    }
};
