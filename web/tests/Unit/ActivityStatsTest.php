<?php

namespace Tests\Unit;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Support\ActivityStats;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityStatsTest extends TestCase
{
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = Carbon::parse('2026-09-30'); // a Wednesday
    }

    private function log(string $date, string $status): ActivityLog
    {
        return (new ActivityLog(['status' => $status]))->forceFill([
            'scheduled_for' => Carbon::parse($date),
        ]);
    }

    public function test_streak_counts_consecutive_completed_days_back_from_today(): void
    {
        $logs = collect([
            $this->log('2026-09-30', 'completed'),
            $this->log('2026-09-29', 'completed'),
            $this->log('2026-09-28', 'completed'),
            $this->log('2026-09-27', 'missed'),
        ]);

        $this->assertSame(3, ActivityStats::streak($logs, $this->today));
    }

    public function test_a_missed_day_breaks_the_streak(): void
    {
        $logs = collect([
            $this->log('2026-09-30', 'completed'),
            $this->log('2026-09-29', 'missed'),
            $this->log('2026-09-28', 'completed'),
        ]);

        $this->assertSame(1, ActivityStats::streak($logs, $this->today));
    }

    public function test_a_skipped_day_breaks_the_streak(): void
    {
        $logs = collect([
            $this->log('2026-09-30', 'completed'),
            $this->log('2026-09-29', 'skipped'),
        ]);

        $this->assertSame(1, ActivityStats::streak($logs, $this->today));
    }

    public function test_an_unlogged_day_breaks_the_streak(): void
    {
        $logs = collect([
            $this->log('2026-09-30', 'completed'),
            // 29 Sep has no log at all.
            $this->log('2026-09-28', 'completed'),
        ]);

        $this->assertSame(1, ActivityStats::streak($logs, $this->today));
    }

    public function test_today_not_yet_logged_does_not_break_a_streak_from_yesterday(): void
    {
        $logs = collect([
            $this->log('2026-09-29', 'completed'),
            $this->log('2026-09-28', 'completed'),
        ]);

        $this->assertSame(2, ActivityStats::streak($logs, $this->today));
    }

    public function test_today_missed_gives_a_zero_streak(): void
    {
        $logs = collect([
            $this->log('2026-09-30', 'missed'),
            $this->log('2026-09-29', 'completed'),
        ]);

        $this->assertSame(0, ActivityStats::streak($logs, $this->today));
    }

    public function test_no_logs_gives_a_zero_streak(): void
    {
        $this->assertSame(0, ActivityStats::streak(collect(), $this->today));
    }

    public function test_completion_by_day_covers_every_day_oldest_first(): void
    {
        $days = ActivityStats::completionByDay(collect(), 7, $this->today);

        $this->assertCount(7, $days);
        $this->assertSame('2026-09-24', $days[0]['date']);
        $this->assertSame('2026-09-30', $days[6]['date']);
        $this->assertSame('Thu', $days[0]['label']);
    }

    public function test_completion_by_day_computes_the_percentage_completed(): void
    {
        $activity = new Activity;
        $logs = collect([
            $this->log('2026-09-29', 'completed'),
            $this->log('2026-09-29', 'completed'),
            $this->log('2026-09-29', 'missed'),
            $this->log('2026-09-28', 'skipped'),
        ]);

        $days = collect(ActivityStats::completionByDay($logs, 7, $this->today))->keyBy('date');

        $this->assertSame(67, $days['2026-09-29']['percentage']); // 2 of 3
        $this->assertSame(0, $days['2026-09-28']['percentage']); // 0 of 1
        $this->assertSame(0, $days['2026-09-27']['percentage']); // no logs that day
    }
}
