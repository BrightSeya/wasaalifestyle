<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleWeekViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday; its week runs Mon 28 Sep - Sun 4 Oct 2026.
        $this->travelTo(Carbon::parse('2026-09-30 10:00'));
        $this->user = User::factory()->create();
    }

    private function entry(string $title, string $start, string $end, ?User $owner = null): Schedule
    {
        return Schedule::factory()->for($owner ?? $this->user)->create([
            'title' => $title,
            'start_datetime' => Carbon::parse($start),
            'end_datetime' => Carbon::parse($end),
        ]);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/schedules/week')->assertRedirect('/login');
    }

    public function test_shows_the_current_week_by_default(): void
    {
        $this->actingAs($this->user)->get('/schedules/week')
            ->assertOk()
            ->assertSee('Week of Sep 28, 2026')
            ->assertSeeInOrder(['Mon 28', 'Tue 29', 'Wed 30', 'Thu 1', 'Fri 2', 'Sat 3', 'Sun 4']);
    }

    public function test_date_selects_the_week_containing_it(): void
    {
        $this->actingAs($this->user)->get('/schedules/week?date=2026-10-08')
            ->assertOk()
            ->assertSee('Week of Oct 5, 2026');
    }

    public function test_invalid_date_falls_back_to_current_week(): void
    {
        $this->actingAs($this->user)->get('/schedules/week?date=not-a-date')
            ->assertOk()
            ->assertSee('Week of Sep 28, 2026');
    }

    public function test_only_this_weeks_entries_of_the_user_are_shown(): void
    {
        $this->entry('Team Meeting', '2026-09-28 10:00', '2026-09-28 11:00');
        $this->entry('Meal Prep', '2026-10-02 16:00', '2026-10-02 17:00');
        $this->entry('Next Week Gym', '2026-10-05 07:00', '2026-10-05 08:00');
        $this->entry('Last Week Call', '2026-09-27 09:00', '2026-09-27 10:00');
        $this->entry('Not Mine', '2026-09-29 12:00', '2026-09-29 13:00', User::factory()->create());

        $this->actingAs($this->user)->get('/schedules/week')
            ->assertSee('Team Meeting')
            ->assertSee('Meal Prep')
            ->assertDontSee('Next Week Gym')
            ->assertDontSee('Last Week Call')
            ->assertDontSee('Not Mine');
    }

    public function test_entries_link_to_their_edit_page(): void
    {
        $schedule = $this->entry('Study Session', '2026-09-29 14:00', '2026-09-29 15:30');

        $this->actingAs($this->user)->get('/schedules/week')
            ->assertSee(route('schedules.edit', $schedule), false);
    }

    public function test_expanded_calendar_card_shows_task_details_and_the_occurrence_action(): void
    {
        $this->entry('Past task', '2026-09-30 09:00', '2026-09-30 09:30');
        $this->entry('Future task', '2026-09-30 11:00', '2026-09-30 11:30');

        $this->actingAs($this->user)->get('/schedules/week')
            ->assertOk()
            ->assertSee('data-occurrence-toggle', false)
            ->assertSee('data-occurrence-details hidden', false)
            ->assertSee('Edit task')
            ->assertSee('Record outcome')
            ->assertSee('Completed')
            ->assertSee('Cancel this occurrence')
            ->assertSee('bg-emerald-50', false)
            ->assertSee('bg-rose-50', false)
            ->assertSee('bg-amber-50', false)
            ->assertDontSee('Manage occurrence')
            ->assertSee('Cancel this task? It will be marked as cancelled in your schedule.', false)
            ->assertSee("event.key === 'Escape'", false);
    }

    public function test_navigation_and_day_links(): void
    {
        $this->actingAs($this->user)->get('/schedules/week')
            ->assertSee(route('schedules.week', ['date' => '2026-09-21']), false)
            ->assertSee(route('schedules.week', ['date' => '2026-10-05']), false)
            ->assertSee(route('schedules.create', ['date' => '2026-10-01']), false);
    }

    public function test_grid_stretches_for_early_entries(): void
    {
        $this->actingAs($this->user)->get('/schedules/week')->assertDontSee('5 AM');

        $this->entry('Early Run', '2026-09-29 05:00', '2026-09-29 05:45');

        $this->actingAs($this->user)->get('/schedules/week')->assertSee('5 AM');
    }

    public function test_sidebar_schedule_link_opens_the_week_view(): void
    {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('href="'.route('schedules.week').'"', false);
    }

    public function test_daily_recurring_entry_appears_on_every_day(): void
    {
        $schedule = $this->entry('Meditation', '2026-09-01 07:00', '2026-09-01 07:15');
        $schedule->update(['is_recurring' => true, 'recurrence_rule' => 'daily']);

        $html = $this->actingAs($this->user)->get('/schedules/week')->assertOk()->getContent();

        $this->assertSame(7, substr_count($html, '<span class="block truncate font-semibold">Meditation</span>'));
    }

    public function test_overlapping_entries_are_displayed_in_separate_calendar_lanes(): void
    {
        $this->entry('Morning Run', '2026-09-29 09:00', '2026-09-29 10:00');
        $this->entry('Team Meeting', '2026-09-29 09:00', '2026-09-29 10:00');

        $html = $this->actingAs($this->user)->get('/schedules/week')->assertOk()->getContent();

        $this->assertStringContainsString('left: calc(0% + 2px); width: calc(50% - 4px);', $html);
        $this->assertStringContainsString('left: calc(50% + 2px); width: calc(50% - 4px);', $html);
    }

    public function test_partially_overlapping_entries_reuse_a_lane_after_an_entry_ends(): void
    {
        $this->entry('Long task', '2026-09-29 09:00', '2026-09-29 11:00');
        $this->entry('First short task', '2026-09-29 09:30', '2026-09-29 10:00');
        $this->entry('Second short task', '2026-09-29 10:00', '2026-09-29 10:30');

        $html = $this->actingAs($this->user)->get('/schedules/week')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'width: calc(50% - 4px);'));
        $this->assertSame(2, substr_count($html, 'left: calc(50% + 2px);'));
    }
}
