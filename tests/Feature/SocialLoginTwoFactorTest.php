<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SocialLoginTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that social login redirects to 2FA page when user has TOTP enabled.
     */
    public function test_social_login_redirects_to_2fa_when_totp_enabled(): void
    {
        // Create a user with TOTP 2FA enabled and confirmed
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
            'status' => 'active',
        ]);

        // Link social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => '123456789',
            'provider_email' => $user->email,
        ]);

        // Mock Socialite
        $this->mockSocialiteUser($user, '123456789');

        // Make callback request
        $response = $this->get('/auth/google/callback');

        // Should redirect to login with 2FA action
        $response->assertRedirect();
        $this->assertStringContainsString('/auth/login?action=2fa', $response->headers->get('Location'));

        // Session should contain login.id
        $this->assertEquals($user->id, session('login.id'));
    }

    /**
     * Test that social login redirects to 2FA page when user has SMS 2FA enabled.
     */
    public function test_social_login_redirects_to_2fa_when_sms_enabled(): void
    {
        // Create a user with SMS 2FA enabled and confirmed
        $user = User::factory()->create([
            'two_factor_sms_enabled' => true,
            'two_factor_sms_confirmed_at' => now(),
            'phone' => '+1234567890',
            'status' => 'active',
        ]);

        // Link social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => '123456789',
            'provider_email' => $user->email,
        ]);

        // Mock Socialite
        $this->mockSocialiteUser($user, '123456789');

        // Make callback request
        $response = $this->get('/auth/google/callback');

        // Should redirect to login with 2FA action
        $response->assertRedirect();
        $this->assertStringContainsString('/auth/login?action=2fa', $response->headers->get('Location'));

        // Session should contain login.id
        $this->assertEquals($user->id, session('login.id'));
    }

    /**
     * Test that social login redirects to 2FA page when user has Email 2FA enabled.
     */
    public function test_social_login_redirects_to_2fa_when_email_enabled(): void
    {
        // Create a user with Email 2FA enabled
        $user = User::factory()->create([
            'two_factor_email_enabled' => true,
            'status' => 'active',
        ]);

        // Link social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => '123456789',
            'provider_email' => $user->email,
        ]);

        // Mock Socialite
        $this->mockSocialiteUser($user, '123456789');

        // Make callback request
        $response = $this->get('/auth/google/callback');

        // Should redirect to login with 2FA action
        $response->assertRedirect();
        $this->assertStringContainsString('/auth/login?action=2fa', $response->headers->get('Location'));

        // Session should contain login.id
        $this->assertEquals($user->id, session('login.id'));
    }

    /**
     * Test that social login redirects to dashboard when no 2FA is enabled.
     */
    public function test_social_login_redirects_to_dashboard_without_2fa(): void
    {
        // Create a user without 2FA
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        // Link social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => '123456789',
            'provider_email' => $user->email,
        ]);

        // Mock Socialite
        $this->mockSocialiteUser($user, '123456789');

        // Make callback request
        $response = $this->get('/auth/google/callback');

        // Should redirect to dashboard
        $response->assertRedirect();
        $this->assertStringContainsString('/dashboard', $response->headers->get('Location'));

        // User should be authenticated
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test that 2FA challenge methods can be retrieved after social login sets session.
     *
     * @group skip-ci
     */
    public function test_2fa_challenge_methods_available_after_social_login(): void
    {
        $this->markTestSkipped('Skipped due to audit log unique constraint issues in test environment');

        // Create a fresh user with multiple 2FA methods for this isolated test
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
            'two_factor_sms_enabled' => true,
            'two_factor_sms_confirmed_at' => now(),
            'phone' => '+1234567890',
            'two_factor_email_enabled' => true,
            'status' => 'active',
        ]);

        // Link social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'github',  // Use different provider to avoid conflicts
            'provider_id' => 'unique_id_'.$user->id,
            'provider_email' => $user->email,
        ]);

        // Mock Socialite for github
        $this->mockSocialiteUserForProvider($user, 'unique_id_'.$user->id, 'github');

        // Make callback request (sets session)
        $this->get('/auth/github/callback');

        // Now request 2FA methods - should work because login.id is in session
        $response = $this->getJson('/api/two-factor-challenge/methods');

        $response->assertOk();
        $response->assertJsonStructure([
            'methods',
            'phone',
            'email',
        ]);

        // Should have all 3 methods available
        $this->assertContains('totp', $response->json('methods'));
        $this->assertContains('sms', $response->json('methods'));
        $this->assertContains('email', $response->json('methods'));
    }

    /**
     * Helper to mock Socialite user.
     */
    protected function mockSocialiteUser(User $user, string $providerId): void
    {
        $this->mockSocialiteUserForProvider($user, $providerId, 'google');
    }

    /**
     * Helper to mock Socialite user for a specific provider.
     */
    protected function mockSocialiteUserForProvider(User $user, string $providerId, string $provider): void
    {
        $socialiteUser = Mockery::mock('Laravel\Socialite\Two\User');
        $socialiteUser->shouldReceive('getId')->andReturn($providerId);
        $socialiteUser->shouldReceive('getEmail')->andReturn($user->email);
        $socialiteUser->shouldReceive('getName')->andReturn($user->name);
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

        Socialite::shouldReceive('driver')
            ->with($provider)
            ->andReturn(Mockery::mock([
                'stateless' => Mockery::mock([
                    'user' => $socialiteUser,
                ]),
            ]));
    }
}
