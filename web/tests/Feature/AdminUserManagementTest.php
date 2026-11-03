<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_paginated_users_and_search_by_name_or_email(): void
    {
        $admin = User::factory()->admin()->create();
        $match = User::factory()->create(['name' => 'Amara Searchable', 'email' => 'amara@example.test']);
        User::factory()->create(['name' => 'Other Person', 'email' => 'findme@example.test']);
        User::factory()->count(16)->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('page=2', false);

        $this->get(route('admin.users.index', ['search' => 'Amara']))
            ->assertOk()
            ->assertSee('Amara Searchable')
            ->assertDontSee('Other Person')
            ->assertSee('Registered users')
            ->assertDontSee($match->password);

        $this->get(route('admin.users.index', ['search' => 'findme@example.test']))
            ->assertOk()
            ->assertSee('Other Person')
            ->assertDontSee('Amara Searchable');
    }

    public function test_admin_can_create_user_and_send_secure_password_setup_notification(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Account',
            'email' => 'new-account@example.test',
            'role' => 'user',
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $user = User::where('email', 'new-account@example.test')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertFalse(Hash::check('password', $user->password));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_admin_can_suspend_and_reactivate_accounts_but_not_their_own(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $initialSessionVersion = $user->session_version;
        $user->createToken('existing-device');

        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['status' => 'suspended'])
            ->assertRedirect();
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame($initialSessionVersion + 1, $user->fresh()->session_version);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        $this->patch(route('admin.users.status', $user), ['status' => 'active'])->assertRedirect();
        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame($initialSessionVersion + 1, $user->fresh()->session_version);

        $this->patch(route('admin.users.status', $admin), ['status' => 'suspended'])
            ->assertSessionHasErrors('status');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_suspended_users_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create(['password' => 'ValidPassword123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'ValidPassword123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_old_web_session_is_rejected_after_suspension_even_after_reactivation(): void
    {
        $user = User::factory()->create();
        $oldSessionVersion = $user->session_version;

        $user->forceFill([
            'status' => 'suspended',
            'session_version' => $oldSessionVersion + 1,
        ])->save();
        $user->forceFill(['status' => 'active'])->save();

        $this->actingAs($user->fresh())
            ->withSession(['auth_session_version' => $oldSessionVersion])
            ->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_suspended_user_cannot_use_an_existing_sanctum_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('existing-device')->plainTextToken;
        $user->forceFill(['status' => 'suspended'])->save();

        $this->withToken($token)->getJson('/api/user')->assertForbidden();
    }

    public function test_admin_can_send_reset_and_user_can_complete_secure_password_reset(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.users.password-reset', $user))->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class);

        auth()->logout();
        $token = Password::createToken($user);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePassword123',
            'password_confirmation' => 'NewSecurePassword123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NewSecurePassword123', $user->fresh()->password));
    }

    public function test_non_admin_cannot_access_user_management_or_password_reset(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
        $this->post(route('admin.users.store'), [
            'name' => 'No Access', 'email' => 'noaccess@example.test', 'role' => 'admin',
        ])->assertForbidden();
        $this->patch(route('admin.users.status', $target), ['status' => 'suspended'])->assertForbidden();
        $this->post(route('admin.users.password-reset', $target))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'noaccess@example.test']);
    }
}
