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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('category', ['work', 'exercise', 'leisure', 'errand', 'social', 'rest']);
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule')->nullable();
            $table->enum('status', ['pending', 'active', 'completed', 'missed'])->default('pending');
            $table->timestamps();

            // Conflict detection and daily/weekly views query by user and time.
            $table->index(['user_id', 'start_datetime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
