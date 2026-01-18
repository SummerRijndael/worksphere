<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class LoginRecaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recaptcha.enabled' => true]);
        config(['sanctum.stateful' => ['localhost', 'localhost:3000']]);
    }

    protected function headers()
    {
        return [
            'Referer' => 'http://localhost',
            'Accept' => 'application/json',
        ];
    }

    public function test_login_fails_with_missing_recaptcha_token()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            // Missing recaptcha_token
        ], $this->headers());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recaptcha_token']);
    }

    public function test_login_succeeds_with_valid_recaptcha_token()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->mock(RecaptchaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('verify')
                ->once()
                ->withAnyArgs()
                ->andReturn([
                    'success' => true,
                    'score' => 0.9,
                    'error' => null,
                ]);
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'valid-token',
        ], $this->headers());

        $response->assertStatus(200);
    }

    public function test_login_fails_with_invalid_recaptcha_token()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->mock(RecaptchaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('verify')
                ->once()
                ->withAnyArgs()
                ->andReturn([
                    'success' => false,
                    'error' => 'reCAPTCHA verification failed.',
                ]);
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'invalid-token',
        ], $this->headers());

        // Controller throws ValidationException with 'message'
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_login_requires_challenge_for_low_score()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        // Mock failure with low score
        $this->mock(RecaptchaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('verify')
                ->once()
                ->withAnyArgs()
                ->andReturn([
                    'success' => false,
                    'score' => 0.3,
                    'error' => 'Suspicious activity detected.',
                ]);
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'low-score-token',
        ], $this->headers());

        $response->assertStatus(422)
            ->assertJson([
                'requires_challenge' => true,
            ]);
    }

    public function test_login_succeeds_with_v2_token_after_challenge()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->mock(RecaptchaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);

            $mock->shouldReceive('verify')->never();

            $mock->shouldReceive('verifyV2')
                ->once()
                ->withAnyArgs()
                ->andReturn([
                    'success' => true,
                    'error' => null,
                ]);
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'dummy-v3-token',
            'recaptcha_v2_token' => 'valid-v2-token',
        ], $this->headers());

        $response->assertStatus(200);
    }
}
