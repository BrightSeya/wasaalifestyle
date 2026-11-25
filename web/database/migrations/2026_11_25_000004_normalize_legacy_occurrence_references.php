<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $toUtc = static fn (string $value): string => Carbon::parse($value, 'Africa/Nairobi')->utc()->format('Y-m-d H:i:s');

        foreach ([
            'schedule_risk_assessments' => ['schedule_id', 'occurrence_start'],
            'recommendations' => ['source_schedule_id', 'source_occurrence_start'],
        ] as $table => [$scheduleKey, $dateColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $dateColumn)) {
                continue;
            }

            DB::table($table)
                ->join('schedules', "{$table}.{$scheduleKey}", '=', 'schedules.id')
                ->whereNull('schedules.timezone')
                ->whereNotNull("{$table}.{$dateColumn}")
                ->select("{$table}.id", "{$table}.{$dateColumn}")
                ->orderBy("{$table}.id")
                ->chunk(500, function ($rows) use ($table, $dateColumn, $toUtc): void {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            $dateColumn => $toUtc($row->{$dateColumn}),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Timestamp references are not reversed because new records may already use UTC.
    }
};
