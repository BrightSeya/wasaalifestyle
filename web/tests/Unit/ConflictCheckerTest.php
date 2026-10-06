<?php

namespace Tests\Unit;

use App\Models\Schedule;
use App\Models\User;
use App\Services\ConflictChecker;
use App\Services\ScheduleOccurrences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConflictCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function checker(): ConflictChecker
    {
        return new ConflictChecker(new ScheduleOccurrences);
    }

    public function test_no_conflicts_when_nothing_overlaps(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(9, 0);

        $conflicts = $this->checker()->check($user, $start, $start->copy()->addHour(), 'medium');

        $this->assertCount(0, $conflicts);
    }

    public function test_detects_overlap_with_a_one_off_entry(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(9, 0);

        Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'priority' => 'medium',
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $conflicts = $this->checker()->check($user, $start->copy()->addMinutes(30), $start->copy()->addMinutes(90), 'medium');

        $this->assertCount(1, $conflicts);
        $this->assertSame('overlap', $conflicts->first()['type']);
    }

    public function test_detects_overlap_with_a_recurring_entry(): void
    {
        $user = User::factory()->create();
        $candidateStart = now()->addDay()->setTime(9, 30);
        $start = $candidateStart->copy()->subWeek()->setTime(9, 0);

        Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);

        $conflicts = $this->checker()->check($user, $candidateStart, $candidateStart->copy()->addHour(), 'medium');

        $this->assertCount(1, $conflicts);
        $this->assertSame('overlap', $conflicts->first()['type']);
    }

    public function test_flags_a_priority_clash_when_candidate_is_lower_priority(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(9, 0);

        Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'priority' => 'high',
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $conflicts = $this->checker()->check($user, $start, $start->copy()->addHour(), 'low');

        $this->assertCount(1, $conflicts);
        $this->assertSame('priority', $conflicts->first()['type']);
    }

    public function test_no_priority_clash_when_candidate_is_equal_or_higher_priority(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(9, 0);

        Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'priority' => 'medium',
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $conflicts = $this->checker()->check($user, $start, $start->copy()->addHour(), 'high');

        $this->assertCount(1, $conflicts);
        $this->assertSame('overlap', $conflicts->first()['type']);
    }

    public function test_excludes_the_given_schedule_id_from_conflicts(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(9, 0);

        $schedule = Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'priority' => 'medium',
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $conflicts = $this->checker()->check($user, $start, $start->copy()->addHour(), 'medium', excludeScheduleId: $schedule->id);

        $this->assertCount(0, $conflicts);
    }
}
