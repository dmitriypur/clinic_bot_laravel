<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->after('id');
            $table->integer('status')->default(1)->after('password');
            $table->string('role', 25)->default('user')->after('status');
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->after('role');
            
            $table->index('username');
            $table->index('status');
            $table->index('role');
            $table->index('clinic_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['username']);
            $table->dropIndex(['status']);
            $table->dropIndex(['role']);
            $table->dropIndex(['clinic_id']);
            $table->dropForeign(['clinic_id']);
            $table->dropColumn(['username', 'status', 'role', 'clinic_id']);
        });
    }
};
