<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Africa/Nairobi')->after('preferences');
        });

        // Older app-created date-times were stored as UTC-shaped values even though
        // users entered them as Nairobi wall-clock times. Convert those values while
        // preserving the time users see. Google Calendar rows already represent UTC.
        $toUtc = static fn (string $value): string => Carbon::parse($value, 'Africa/Nairobi')->utc()->format('Y-m-d H:i:s');

        DB::table('schedules')->whereNull('timezone')->orderBy('id')->chunk(500, function ($rows) use ($toUtc): void {
            foreach ($rows as $row) {
                DB::table('schedules')->where('id', $row->id)->update([
                    'start_datetime' => $toUtc($row->start_datetime),
                    'end_datetime' => $toUtc($row->end_datetime),
                ]);
            }
        });

        DB::table('activity_logs')->orderBy('id')->chunk(500, function ($rows) use ($toUtc): void {
            foreach ($rows as $row) {
                DB::table('activity_logs')->where('id', $row->id)->update([
                    'scheduled_for' => $toUtc($row->scheduled_for),
                ]);
            }
        });

        DB::table('recommendations')->orderBy('id')->chunk(500, function ($rows) use ($toUtc): void {
            foreach ($rows as $row) {
                DB::table('recommendations')->where('id', $row->id)->update([
                    'suggested_start' => $toUtc($row->suggested_start),
                    'suggested_end' => $toUtc($row->suggested_end),
                ]);
            }
        });

        DB::table('schedule_occurrence_outcomes')
            ->join('schedules', 'schedule_occurrence_outcomes.schedule_id', '=', 'schedules.id')
            ->whereNull('schedules.timezone')
            ->select('schedule_occurrence_outcomes.id', 'schedule_occurrence_outcomes.occurrence_start', 'schedule_occurrence_outcomes.occurrence_end')
            ->orderBy('schedule_occurrence_outcomes.id')
            ->chunk(500, function ($rows) use ($toUtc): void {
                foreach ($rows as $row) {
                    DB::table('schedule_occurrence_outcomes')->where('id', $row->id)->update([
                        'occurrence_start' => $toUtc($row->occurrence_start),
                        'occurrence_end' => $row->occurrence_end ? $toUtc($row->occurrence_end) : null,
                    ]);
                }
            });

    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
