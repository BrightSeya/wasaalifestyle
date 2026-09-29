<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_the_user_sidebar(): void
    {
        $user = User::factory()->create(['name' => 'Bright Dev']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Dashboard', 'Schedule', 'Activities', 'Recommendations', 'Settings'])
            ->assertSee('Bright Dev')
            ->assertSee('Log out')
            ->assertDontSee('User Management');
    }

    public function test_current_page_is_marked_active(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_admin_sees_the_admin_sidebar(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Admin portal')
            ->assertSeeInOrder(['Dashboard', 'User Management', 'System Logs', 'Performance'])
            ->assertDontSee('Recommendations');
    }
}
