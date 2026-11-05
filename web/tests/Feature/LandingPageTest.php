<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_landing_page_explains_wasaa_and_links_to_signup_and_login(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Make room for')
            ->assertSee('landing-header sticky top-0', false)
            ->assertSee('min-h-24', false)
            ->assertSee('data-theme-toggle', false)
            ->assertSee('theme.js')
            ->assertSee('Your plan, at a glance')
            ->assertSee('Spot conflicts early')
            ->assertSee('Illustrative preview')
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('login').'"', false);
    }

    public function test_authenticated_landing_page_links_to_the_correct_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('register').'"', false);
    }
}
