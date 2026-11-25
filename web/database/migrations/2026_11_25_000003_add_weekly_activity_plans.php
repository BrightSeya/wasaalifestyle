<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activities', 'weekly_slots')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->json('weekly_slots')->nullable()->after('frequency');
            });
        }

        if (! Schema::hasColumn('schedules', 'activity_id')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->foreignId('activity_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            });

            return;
        }

        $activityForeignKey = collect(Schema::getForeignKeys('schedules'))
            ->first(fn (array $foreignKey) => in_array('activity_id', $foreignKey['columns'], true));
        if ($activityForeignKey
            && $activityForeignKey['foreign_table'] === 'activities'
            && $activityForeignKey['on_delete'] === 'cascade') {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) use ($activityForeignKey) {
            if ($activityForeignKey) {
                $table->dropForeign($activityForeignKey['name']);
            }
            $table->foreign('activity_id')->references('id')->on('activities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('weekly_slots');
        });
    }
};
