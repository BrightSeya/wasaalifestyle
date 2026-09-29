<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleOccurrences;
use App\Support\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ScheduleOccurrences $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->service = new ScheduleOccurrences;
    }

    private function entry(string $start, string $end, ?string $rule = null, ?User $owner = null): Schedule
    {
        return Schedule::factory()->for($owner ?? $this->user)->create([
            'start_datetime' => Carbon::parse($start),
            'end_datetime' => Carbon::parse($end),
            'is_recurring' => $rule !== null,
            'recurrence_rule' => $rule,
        ]);
    }

    /** Occurrence start times for the week Mon 28 Sep - Sun 4 Oct 2026. */
    private function weekStarts(): array
    {
        return $this->service
            ->between($this->user, Carbon::parse('2026-09-28'), Carbon::parse('2026-10-05'))
            ->map(fn (Occurrence $o) => $o->start->format('Y-m-d H:i'))
            ->all();
    }

    public function test_one_off_entries_appear_once_and_only_in_range(): void
    {
        $this->entry('2026-09-30 10:00', '2026-09-30 11:00');
        $this->entry('2026-10-06 10:00', '2026-10-06 11:00');
        $this->entry('2026-09-27 10:00', '2026-09-27 11:00');

        $this->assertSame(['2026-09-30 10:00'], $this->weekStarts());
    }

    public function test_daily_entry_repeats_from_its_start_date_onward(): void
    {
        $this->entry('2026-10-01 07:00', '2026-10-01 07:45', 'daily');

        $this->assertSame([
            '2026-10-01 07:00', '2026-10-02 07:00', '2026-10-03 07:00', '2026-10-04 07:00',
        ], $this->weekStarts());
    }

    public function test_daily_entry_that_started_earlier_fills_the_whole_week(): void
    {
        $this->entry('2026-09-01 07:00', '2026-09-01 07:45', 'daily');

        $this->assertCount(7, $this->weekStarts());
        $this->assertSame('2026-09-28 07:00', $this->weekStarts()[0]);
    }

    public function test_weekly_entry_repeats_on_the_same_weekday(): void
    {
        // Tuesday 15 Sep -> next Tuesdays are 22 Sep, 29 Sep, ...
        $this->entry('2026-09-15 14:00', '2026-09-15 15:30', 'weekly');

        $this->assertSame(['2026-09-29 14:00'], $this->weekStarts());
    }

    public function test_weekly_entry_starting_on_the_first_day_of_the_range(): void
    {
        $this->entry('2026-09-28 09:00', '2026-09-28 10:00', 'weekly');

        $this->assertSame(['2026-09-28 09:00'], $this->weekStarts());
    }

    public function test_recurring_entry_starting_after_the_range_is_not_shown(): void
    {
        $this->entry('2026-10-10 09:00', '2026-10-10 10:00', 'daily');

        $this->assertSame([], $this->weekStarts());
    }

    public function test_occurrences_keep_the_original_duration(): void
    {
        $this->entry('2026-09-15 14:00', '2026-09-15 15:30', 'weekly');

        $occurrence = $this->service->between($this->user, Carbon::parse('2026-09-28'), Carbon::parse('2026-10-05'))->first();

        $this->assertSame('15:30', $occurrence->end->format('H:i'));
        $this->assertSame(90, (int) $occurrence->start->diffInMinutes($occurrence->end));
    }

    public function test_occurrences_are_sorted_and_exclude_other_users(): void
    {
        $this->entry('2026-09-30 18:00', '2026-09-30 19:00');
        $this->entry('2026-09-01 07:00', '2026-09-01 07:30', 'daily');
        $this->entry('2026-09-30 12:00', '2026-09-30 13:00', null, User::factory()->create());

        $starts = $this->weekStarts();

        $this->assertCount(8, $starts);
        $this->assertSame($starts, collect($starts)->sort()->values()->all());
        $this->assertNotContains('2026-09-30 12:00', $starts);
    }
}
