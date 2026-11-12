<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->foreignId('source_schedule_id')->nullable()->after('activity_id')
                ->constrained('schedules')->cascadeOnDelete();
            $table->dateTime('source_occurrence_start')->nullable()->after('source_schedule_id');
            $table->timestamp('source_schedule_updated_at')->nullable()->after('source_occurrence_start');
            $table->char('source_schedule_fingerprint', 64)->nullable()->after('source_schedule_updated_at');
            $table->boolean('is_suitable')->nullable()->after('confidence_score');
            $table->index(['source_schedule_id', 'source_occurrence_start', 'status'], 'recommendation_source_occurrence_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex('recommendation_source_occurrence_status_idx');
            $table->dropConstrainedForeignId('source_schedule_id');
            $table->dropColumn(['source_occurrence_start', 'source_schedule_updated_at', 'source_schedule_fingerprint', 'is_suitable']);
        });
    }
};
