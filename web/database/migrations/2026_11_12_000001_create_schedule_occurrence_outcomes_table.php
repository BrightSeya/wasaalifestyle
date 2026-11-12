<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_occurrence_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->dateTime('occurrence_start');
            $table->enum('outcome', ['completed', 'missed', 'skipped', 'cancelled']);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['schedule_id', 'occurrence_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_occurrence_outcomes');
    }
};
