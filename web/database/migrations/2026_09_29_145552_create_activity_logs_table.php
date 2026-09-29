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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_for');
            $table->enum('status', ['completed', 'missed', 'skipped']);
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scheduled_for']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
