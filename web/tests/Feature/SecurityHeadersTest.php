<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_security_headers_are_added_to_responses(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_production_responses_use_content_security_policy_and_secure_transport_only_over_https(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $policy);

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_production_nonce_allows_inline_scripts_on_recommendation_forms_without_unsafe_inline(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $user = User::factory()->create(['status' => 'active']);

        foreach (['/schedules/create', '/activities/create'] as $path) {
            $response = $this->withSession(['auth_session_version' => $user->session_version])
                ->actingAs($user)
                ->get($path)
                ->assertOk();
            $policy = $response->headers->get('Content-Security-Policy');
            preg_match("/script-src[^;]*'nonce-([^']+)'/", $policy, $matches);

            $this->assertNotEmpty($matches[1] ?? null);
            $this->assertStringContainsString('<script nonce="'.$matches[1].'">', $response->getContent());
            $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $policy);
        }
    }

    public function test_development_responses_do_not_receive_production_csp_or_hsts(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
