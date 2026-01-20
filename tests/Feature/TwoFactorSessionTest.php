<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TwoFactorSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the full 2FA flow including session regeneration and subsequent API access.
     */
    public function test_2fa_verification_and_session_regeneration_flow(): void
    {
        // 1. Create a user with TOTP 2FA enabled
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
            'status' => 'active',
        ]);

        // Disable Audit Logging
        \Illuminate\Support\Facades\Config::set('audit.models', []);
        \Illuminate\Support\Facades\Config::set('audit.async', false);
        
        // Disable Recaptcha for this test
        \Illuminate\Support\Facades\Config::set('recaptcha.enabled', false);

        // 2. Attempt login (should trigger 2FA challenge)
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password', // Assumes factory sets password to 'password'
        ], ['Referer' => 'http://localhost']);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'requires_2fa' => true,
                ],
            ]);

        // Assert user is NOT fully authenticated yet
        $this->assertFalse(Auth::check());
        
        // Assert session has the login.id
        $this->assertEquals($user->id, session('login.id'));
        $initialSessionId = session()->getId();

        // 3. Verify 2FA challenge
        // We need to generate a valid TOTP code. 
        // Since we mocked the secret, we can use a library or just mock the verification in the controller if we wanted, 
        // but it's better to use a real code if possible. 
        // However, generating a TOTP code in test might be complex without the library content.
        // Let's rely on the fact that we can mock the Google2FA provider OR just force the verification to pass 
        // by mocking the method if needed.
        
        // Actually, looking at TwoFactorController, it uses PragmaRX\Google2FA\Google2FA.
        
        // For simplicity, let's mock the Verify action in the controller? 
        // No, that changes the code we are testing. 
        // Let's create a recovery code which is easier to test.
        
        $recoveryCode = '12345678-1234-1234-1234-123456789012';
        $user->update([
            'two_factor_recovery_codes' => encrypt(json_encode([$recoveryCode])),
        ]);

        $verifyResponse = $this->postJson('/api/two-factor-challenge', [
            'code' => $recoveryCode, // Using recovery code behaves effectively the same for auth
        ], ['Referer' => 'http://localhost']);

        $verifyResponse->assertStatus(200)
            ->assertJsonPath('message', 'Two-factor authentication verified');

        // 4. Assert User is Authenticated
        $this->assertTrue(Auth::check());
        $this->assertEquals($user->id, Auth::id());

        // 5. Assert Session ID changed (Regenerated)
        $newSessionId = session()->getId();
        $this->assertNotEquals($initialSessionId, $newSessionId);

        // 6. Attempt a subsequent API call (Dashboard)
        // This is key: The test client handles cookies automatically. 
        // If this passes, it means the backend session logic is sound. 
        // The issue likely resides in the FRONTEND handling of the new CSRF token.
        
        $dashboardResponse = $this->getJson('/api/dashboard', ['Referer' => 'http://localhost']);
        
        $dashboardResponse->assertStatus(200);
    }
}
