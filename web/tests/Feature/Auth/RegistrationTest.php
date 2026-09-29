<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

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

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', 'bright@example.com')->first();
        $this->assertSame('user', $user->role);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotSame('secret123', $user->password);
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
}
