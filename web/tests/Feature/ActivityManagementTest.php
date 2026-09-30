<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityManagementTest extends TestCase
{
    use RefreshDatabase;

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Morning Run',
            'category' => 'exercise',
            'preferred_time_of_day' => '06:30',
            'frequency' => 5,
            'duration_minutes' => 45,
        ], $overrides);
    }

    public function test_guests_cannot_access_activities(): void
    {
        $this->get('/activities')->assertRedirect('/login');
        $this->post('/activities', $this->validData())->assertRedirect('/login');
    }

    public function test_index_lists_only_the_users_activities(): void
    {
        $user = User::factory()->create();
        Activity::factory()->for($user)->create(['name' => 'My Activity']);
        Activity::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($user)->get('/activities')
            ->assertOk()
            ->assertSee('My Activity')
            ->assertDontSee('Someone Else');
    }

    public function test_user_can_create_an_activity(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData())
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity added.');

        $activity = $user->activities()->sole();
        $this->assertSame('Morning Run', $activity->name);
        $this->assertSame(Category::Exercise, $activity->category);
        $this->assertSame('06:30', substr($activity->preferred_time_of_day, 0, 5));
        $this->assertSame(5, $activity->frequency);
        $this->assertSame(45, $activity->duration_minutes);
    }

    public function test_activity_cannot_be_created_for_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData(['user_id' => $other->id]));

        $this->assertSame(0, $other->activities()->count());
        $this->assertSame(1, $user->activities()->count());
    }

    public function test_validation_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData(['category' => 'gaming']))
            ->assertSessionHasErrors('category');
        $this->actingAs($user)->post('/activities', $this->validData(['frequency' => 8]))
            ->assertSessionHasErrors('frequency');
        $this->actingAs($user)->post('/activities', $this->validData(['name' => '', 'duration_minutes' => 0]))
            ->assertSessionHasErrors(['name', 'duration_minutes']);

        $this->assertSame(0, Activity::count());
    }

    public function test_user_can_edit_and_update_their_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->get(route('activities.edit', $activity))->assertOk()->assertSee($activity->name);

        $this->actingAs($user)->put(route('activities.update', $activity), $this->validData(['name' => 'Updated']))
            ->assertRedirect(route('activities.index'));

        $this->assertSame('Updated', $activity->fresh()->name);
    }

    public function test_user_can_delete_their_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('activities.destroy', $activity))
            ->assertRedirect(route('activities.index'));

        $this->assertModelMissing($activity);
    }

    public function test_user_cannot_touch_another_users_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->create();

        $this->actingAs($user)->get(route('activities.edit', $activity))->assertForbidden();
        $this->actingAs($user)->put(route('activities.update', $activity), $this->validData())->assertForbidden();
        $this->actingAs($user)->delete(route('activities.destroy', $activity))->assertForbidden();

        $this->assertModelExists($activity);
    }
}
