<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'risk_assessment_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('risk_assessment_id')->nullable()->after('schedule_id');
            });
        }

        if (! Schema::hasIndex('notifications', ['risk_assessment_id'], 'unique')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->unique('risk_assessment_id');
            });
        }

        $hasRiskAssessmentForeignKey = collect(Schema::getForeignKeys('notifications'))
            ->contains(fn (array $foreignKey): bool =>
                $foreignKey['columns'] === ['risk_assessment_id']
                && $foreignKey['foreign_table'] === 'schedule_risk_assessments'
            );

        if (! $hasRiskAssessmentForeignKey) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->foreign('risk_assessment_id', 'notifications_risk_assessment_id_foreign')
                    ->references('id')->on('schedule_risk_assessments')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('notifications', 'dismissed_at')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->timestamp('dismissed_at')->nullable()->after('is_read');
            });
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('risk_assessment_id');
            $table->dropColumn('dismissed_at');
        });
    }
};
