<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_occurrence_outcomes', function (Blueprint $table) {
            // Nullable so earlier records are not assigned a duration that may have changed later.
            $table->dateTime('occurrence_end')->nullable()->after('occurrence_start');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_occurrence_outcomes', function (Blueprint $table) {
            $table->dropColumn('occurrence_end');
        });
    }
};
