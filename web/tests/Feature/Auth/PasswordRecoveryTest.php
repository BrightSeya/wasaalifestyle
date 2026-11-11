<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_STATUS = 'If an account with that email exists, a password reset link will be sent.';

    public function test_guest_can_open_password_recovery_form_from_login(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'), false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee(route('password.email'), false)
            ->assertSee(route('login'), false);
    }

    public function test_existing_account_receives_reset_notification_and_generic_confirmation(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::GENERIC_STATUS);

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertGuest();
    }

    public function test_user_can_reset_password_from_the_self_service_email_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', self::GENERIC_STATUS);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Choose a password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'RecoveredPassword123',
            'password_confirmation' => 'RecoveredPassword123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('RecoveredPassword123', $user->fresh()->password));
    }

    public function test_unknown_email_receives_the_same_confirmation_without_a_notification(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'missing@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::GENERIC_STATUS);

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_password_recovery_request_requires_a_valid_email(): void
    {
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'not-an-email'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');
    }

    public function test_password_recovery_requests_are_throttled_per_ip(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
                ->post(route('password.email'), ['email' => "missing{$attempt}@example.test"])
                ->assertRedirect(route('password.request'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
            ->post(route('password.email'), ['email' => 'sixth@example.test'])
            ->assertTooManyRequests();
    }
}
