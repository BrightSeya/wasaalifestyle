<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleDayViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->get('/schedules/day')->assertRedirect('/login');
    }

    public function test_shows_today_by_default(): void
    {
        $this->actingAs($this->user)->get('/schedules/day')
            ->assertOk()
            ->assertSee('Today, Sep 30, 2026');
    }

    public function test_date_selects_the_day(): void
    {
        $this->actingAs($this->user)->get('/schedules/day?date=2026-10-02')
            ->assertOk()
            ->assertSee('Friday, Oct 2, 2026');
    }

    public function test_lists_only_that_days_entries_in_time_order(): void
    {
        $this->entry('Evening Errand', '2026-09-30 18:00', '2026-09-30 19:00');
        $this->entry('Morning Run', '2026-09-30 06:30', '2026-09-30 07:15');
        $this->entry('Tomorrow Meeting', '2026-10-01 09:00', '2026-10-01 10:00');
        $this->entry('Not Mine', '2026-09-30 12:00', '2026-09-30 13:00', User::factory()->create());

        $this->actingAs($this->user)->get('/schedules/day')
            ->assertSeeInOrder(['6:30 AM', 'Morning Run', '6:00 PM', 'Evening Errand'])
            ->assertDontSee('Tomorrow Meeting')
            ->assertDontSee('Not Mine');
    }

    public function test_empty_day_shows_a_message(): void
    {
        $this->actingAs($this->user)->get('/schedules/day')
            ->assertSee('Nothing scheduled for this day.');
    }

    public function test_navigation_links(): void
    {
        $this->actingAs($this->user)->get('/schedules/day')
            ->assertSee(route('schedules.day', ['date' => '2026-09-29']), false)
            ->assertSee(route('schedules.day', ['date' => '2026-10-01']), false)
            ->assertSee(route('schedules.week', ['date' => '2026-09-30']), false)
            ->assertSee(route('schedules.create', ['date' => '2026-09-30']), false);
    }
}
