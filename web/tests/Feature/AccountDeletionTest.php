<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdminAuditLog;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_offers_account_deletion(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Delete account')
            ->assertSee('Type DELETE to confirm')
            ->assertSee('Current Password');
    }

    public function test_user_can_delete_their_account_and_associated_data(): void
    {
        config(['session.driver' => 'database']);
        Storage::fake('public');

        $user = User::factory()->create();
        $profilePhoto = UploadedFile::fake()->image('profile.png')->store("profile-photos/{$user->id}", 'public');
        $user->update(['profile_photo_path' => $profilePhoto]);
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = Activity::factory()->create(['user_id' => $user->id]);
        $schedule = Schedule::factory()->create(['user_id' => $user->id]);
        Recommendation::factory()->create(['user_id' => $user->id, 'activity_id' => $activity->id]);

        DB::table('activity_logs')->insert([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'scheduled_for' => now(),
            'status' => 'completed',
            'logged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'message' => 'A test notification',
            'type' => 'system_message',
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('google_calendar_connections')->insert([
            'user_id' => $user->id,
            'access_token' => 'encrypted-access-token',
            'refresh_token' => 'encrypted-refresh-token',
            'scope' => 'https://www.googleapis.com/auth/calendar.events.readonly',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $user->createToken('account-delete-test');
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('reset-token'),
            'created_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'other-user-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $auditLog = AdminAuditLog::create([
            'administrator_id' => $admin->id,
            'affected_user_id' => $user->id,
            'action' => 'user.created',
        ]);

        $this->actingAs($user)->delete(route('settings.account.destroy'), [
            'delete_current_password' => 'password',
            'confirmation' => 'DELETE',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your account and associated data have been deleted.');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('public')->assertMissing($profilePhoto);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-user-session']);
        $this->assertDatabaseMissing('activities', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('schedules', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('recommendations', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $user->id]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'id' => $auditLog->id,
            'administrator_id' => $admin->id,
            'affected_user_id' => null,
            'action' => 'user.created',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Your account and associated data have been deleted.');
    }

    public function test_account_deletion_requires_the_correct_password_and_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('settings.account.destroy'), [
            'delete_current_password' => 'wrong-password',
            'confirmation' => 'DELETE',
        ])->assertSessionHasErrors('delete_current_password');

        $this->delete(route('settings.account.destroy'), [
            'delete_current_password' => 'password',
            'confirmation' => 'delete',
        ])->assertSessionHasErrors('confirmation');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deletion_password_error_is_only_attached_to_the_deletion_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.edit'))
            ->followingRedirects()
            ->delete(route('settings.account.destroy'), [
                'delete_current_password' => 'wrong-password',
                'confirmation' => 'DELETE',
            ]);

        $response->assertOk()->assertSee('name="delete_current_password"', false);

        $errors = $response->viewData('errors')->getBag('default');
        $this->assertTrue($errors->has('delete_current_password'));
        $this->assertFalse($errors->has('current_password'));
        $this->assertSame(1, substr_count($response->getContent(), e($errors->first('delete_current_password'))));
        preg_match_all('/<details\b(?=[^>]*data-settings-disclosure)[^>]*>/', $response->getContent(), $disclosures);
        $this->assertCount(2, $disclosures[0]);
        $this->assertDoesNotMatchRegularExpression('/\sopen(?:\s|>)/', $disclosures[0][0]);
        $this->assertMatchesRegularExpression('/\sopen(?:\s|>)/', $disclosures[0][1]);
    }

    public function test_last_active_administrator_cannot_delete_their_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->delete(route('settings.account.destroy'), [
            'delete_current_password' => 'password',
            'confirmation' => 'DELETE',
        ])->assertSessionHasErrors('account');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
