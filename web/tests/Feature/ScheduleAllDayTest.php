<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleAllDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_day_all_day_entry_appears_on_each_day_and_can_be_edited(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $schedule = $user->schedules()->create([
            'title' => 'Conference',
            'category' => 'work',
            'start_datetime' => '2026-11-10 00:00:00',
            'end_datetime' => '2026-11-12 00:00:00',
            'is_all_day' => true,
            'priority' => 'medium',
            'is_recurring' => false,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->get(route('schedules.day', ['date' => '2026-11-11']))
            ->assertOk()
            ->assertSee('Conference')
            ->assertSee('All day');
        $this->actingAs($user)->get(route('schedules.week', ['date' => '2026-11-11']))
            ->assertOk()
            ->assertSee('data-all-day="2026-11-11"', false)
            ->assertSee('Conference');

        $this->actingAs($user)->get(route('schedules.edit', $schedule))
            ->assertOk()
            ->assertSee('name="is_all_day" type="checkbox" value="1" checked', false)
            ->assertSee('name="end_date" type="date" value="2026-11-11"', false);

        $this->put(route('schedules.update', $schedule), [
            'title' => 'Conference updated',
            'description' => '',
            'category' => 'work',
            'date' => '2026-11-10',
            'is_all_day' => '1',
            'end_date' => '2026-11-12',
            'priority' => 'medium',
            'recurrence' => 'none',
        ])->assertRedirect();

        $this->assertSame('Conference updated', $schedule->fresh()->title);
        $this->assertTrue($schedule->fresh()->is_all_day);
        $this->assertSame('2026-11-13 00:00:00', $schedule->fresh()->end_datetime->format('Y-m-d H:i:s'));
    }

    public function test_timed_multi_day_entry_is_visible_on_its_later_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $user->schedules()->create([
            'title' => 'Overnight travel',
            'category' => 'errand',
            'start_datetime' => '2026-11-10 22:00:00',
            'end_datetime' => '2026-11-11 01:00:00',
            'is_all_day' => false,
            'priority' => 'medium',
            'is_recurring' => false,
        ]);

        $this->actingAs($user)->get(route('schedules.day', ['date' => '2026-11-11']))
            ->assertOk()
            ->assertSee('Overnight travel')
            ->assertSee('12:00 AM - 1:00 AM');
    }
}
