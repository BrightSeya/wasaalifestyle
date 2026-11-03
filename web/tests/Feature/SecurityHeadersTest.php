<?php

namespace Tests\Feature;

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

        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; font-src 'self' data: https://fonts.bunny.net; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; script-src 'self'; connect-src 'self'");

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_development_responses_do_not_receive_production_csp_or_hsts(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
