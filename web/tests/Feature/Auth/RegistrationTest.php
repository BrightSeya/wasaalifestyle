<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private array $valid = [
        'name' => 'Bright Dev',
        'email' => 'bright@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ];

    public function test_registration_page_renders(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create Your Account');
    }

    public function test_user_can_register_and_is_logged_in(): void
    {
        $response = $this->post('/register', $this->valid);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'bright@example.com')->first();
        $this->assertSame('user', $user->role);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotSame('secret123', $user->password);
        $this->assertSame($user->session_version, session('auth_session_version'));
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_registration_cannot_set_admin_role(): void
    {
        $this->post('/register', $this->valid + ['role' => 'admin']);

        $this->assertSame('user', User::first()->role);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'bright@example.com']);

        $this->post('/register', $this->valid)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_must_be_confirmed_and_strong(): void
    {
        $this->post('/register', ['password_confirmation' => 'different1'] + $this->valid)
            ->assertSessionHasErrors('password');

        $this->post('/register', ['password' => 'short', 'password_confirmation' => 'short'] + $this->valid)
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_required_fields_are_validated(): void
    {
        $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
                ->post('/register', [])
                ->assertSessionHasErrors(['name', 'email', 'password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
            ->post('/register', $this->valid)
            ->assertTooManyRequests();
    }

    public function test_user_can_verify_email_with_a_signed_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Email address verified.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_can_resend_verification_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_user_cannot_access_sanctum_api(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('unverified')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertForbidden();
    }
}
