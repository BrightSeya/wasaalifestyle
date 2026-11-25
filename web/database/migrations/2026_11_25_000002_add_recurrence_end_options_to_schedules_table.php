<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->date('recurrence_end_date')->nullable()->after('recurrence_rule');
            $table->unsignedInteger('recurrence_count')->nullable()->after('recurrence_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['recurrence_end_date', 'recurrence_count']);
        });
    }
};
