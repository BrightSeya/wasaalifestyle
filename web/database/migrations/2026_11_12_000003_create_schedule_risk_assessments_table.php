<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->dateTime('occurrence_start');
            $table->decimal('probability', 5, 4);
            $table->string('model_version', 128);
            $table->timestamp('assessed_at');
            $table->timestamp('schedule_updated_at');
            $table->timestamps();

            $table->unique(['schedule_id', 'occurrence_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_risk_assessments');
    }
};
