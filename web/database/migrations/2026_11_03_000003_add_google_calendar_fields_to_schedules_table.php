<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('google_event_id')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unique(['user_id', 'google_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'google_event_id']);
            $table->dropColumn(['google_event_id', 'timezone']);
        });
    }
};
