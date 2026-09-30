<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_settings_page_shows_current_details_and_defaults(): void
    {
        $user = User::factory()->create(['name' => 'Bright Dev']);

        $this->actingAs($user)->get('/settings')
            ->assertOk()
            ->assertSee('Bright Dev')
            ->assertSee('value="9"', false)  // default peak start hour
            ->assertSee('value="17"', false); // default peak end hour
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])->assertRedirect(route('settings.edit'))->assertSessionHas('status', 'Profile updated.');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_email_must_stay_unique_but_can_be_kept_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'mine@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)->put('/settings/profile', ['name' => $user->name, 'email' => 'mine@example.com'])
            ->assertSessionDoesntHaveErrors('email');

        $this->actingAs($user)->put('/settings/profile', ['name' => $user->name, 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_user_can_change_their_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'password',
            'new_password' => 'newpass123',
            'new_password_confirmation' => 'newpass123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'wrong-password',
            'new_password' => 'newpass123',
            'new_password_confirmation' => 'newpass123',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_new_password_requires_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'new_password' => 'newpass123',
            'new_password_confirmation' => 'newpass123',
        ])->assertSessionHasErrors('current_password');
    }

    private function validPreferences(array $overrides = []): array
    {
        return array_merge([
            'peak_start_hour' => 8,
            'peak_end_hour' => 18,
            'preferred_break_minutes' => 20,
            'notify_conflicts' => '1',
            'availability' => ['mon' => ['morning' => '1']],
        ], $overrides);
    }

    public function test_user_can_update_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/preferences', $this->validPreferences())
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('status', 'Preferences updated.');

        $preferences = $user->fresh()->preferences;
        $this->assertSame(8, $preferences['peak_start_hour']);
        $this->assertSame(18, $preferences['peak_end_hour']);
        $this->assertSame(20, $preferences['preferred_break_minutes']);
        $this->assertTrue($preferences['notify_conflicts']);
        $this->assertTrue($preferences['availability']['mon']['morning']);
    }

    public function test_unchecked_boxes_are_stored_as_false(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/preferences', $this->validPreferences());

        $preferences = $user->fresh()->preferences;
        $this->assertFalse($preferences['notify_daily_summary']);
        $this->assertFalse($preferences['notify_recommendations']);
        $this->assertFalse($preferences['availability']['mon']['afternoon']);
        $this->assertFalse($preferences['availability']['sun']['evening']);
    }

    public function test_peak_end_hour_must_be_after_peak_start_hour(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/preferences', $this->validPreferences(['peak_start_hour' => 18, 'peak_end_hour' => 8]))
            ->assertSessionHasErrors('peak_end_hour');
    }

    public function test_preferred_break_minutes_must_be_within_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/preferences', $this->validPreferences(['preferred_break_minutes' => 200]))
            ->assertSessionHasErrors('preferred_break_minutes');
    }
}
