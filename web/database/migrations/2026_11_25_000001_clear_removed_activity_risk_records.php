<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Risk assessments, their alerts, and schedule-alternative rows were generated only
     * for the removed activity-risk feature. Clear those derived records, while retaining
     * schedules and user-recorded outcomes used by the slot recommender.
     */
    public function up(): void
    {
        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'risk_assessment_id')) {
            DB::table('notifications')->whereNotNull('risk_assessment_id')->delete();
            Schema::table('notifications', function ($table) {
                $table->dropForeign(['risk_assessment_id']);
                $table->dropUnique('notifications_risk_assessment_id_unique');
                $table->dropColumn('risk_assessment_id');
            });
        }

        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'dismissed_at')) {
            Schema::table('notifications', function ($table) {
                $table->dropColumn('dismissed_at');
            });
        }

        if (Schema::hasTable('schedule_risk_assessments')) {
            DB::table('schedule_risk_assessments')->delete();
            Schema::dropIfExists('schedule_risk_assessments');
        }

        if (Schema::hasTable('recommendations') && Schema::hasColumn('recommendations', 'source_schedule_id')) {
            DB::table('recommendations')->whereNotNull('source_schedule_id')->delete();
            Schema::table('recommendations', function ($table) {
                $table->dropForeign(['source_schedule_id']);
            });
            Schema::table('recommendations', function ($table) {
                // MySQL requires the foreign key's supporting index to remain until
                // the foreign key itself has been removed, so drop it in a later ALTER.
                $table->dropIndex('recommendation_source_occurrence_status_idx');
                $table->dropColumn([
                    'source_schedule_id',
                    'source_occurrence_start',
                    'source_schedule_updated_at',
                    'source_schedule_fingerprint',
                ]);
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'commitment_risk_consent_at')) {
            Schema::table('users', function ($table) {
                $table->dropColumn('commitment_risk_consent_at');
            });
        }

        Schema::dropIfExists('schedule_reschedule_events');
    }

    public function down(): void
    {
        // Removed derived records cannot be reconstructed.
    }
};
