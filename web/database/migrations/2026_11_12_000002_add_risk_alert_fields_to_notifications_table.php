<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('risk_assessment_id')->nullable()->unique()->after('schedule_id')
                ->constrained('schedule_risk_assessments')->cascadeOnDelete();
            $table->timestamp('dismissed_at')->nullable()->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('risk_assessment_id');
            $table->dropColumn('dismissed_at');
        });
    }
};
