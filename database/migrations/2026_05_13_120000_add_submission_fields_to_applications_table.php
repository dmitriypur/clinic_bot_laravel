<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'submission_source')) {
                $table->string('submission_source', 100)->nullable()->after('source');
                $table->index('submission_source');
            }

            if (! Schema::hasColumn('applications', 'submission_type')) {
                $table->string('submission_type', 100)->nullable()->after('submission_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'submission_source')) {
                $table->dropIndex(['submission_source']);
                $table->dropColumn('submission_source');
            }

            if (Schema::hasColumn('applications', 'submission_type')) {
                $table->dropColumn('submission_type');
            }
        });
    }
};
