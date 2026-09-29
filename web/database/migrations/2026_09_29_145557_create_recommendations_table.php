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
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null when the recommendation is for a one-off task rather than a recurring activity.
            $table->foreignId('activity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('category', ['work', 'exercise', 'leisure', 'errand', 'social', 'rest']);
            $table->dateTime('suggested_start');
            $table->dateTime('suggested_end');
            $table->decimal('confidence_score', 5, 4);
            $table->enum('status', ['pending', 'accepted', 'dismissed'])->default('pending');
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
