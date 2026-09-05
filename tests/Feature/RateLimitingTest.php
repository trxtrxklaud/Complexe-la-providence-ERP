<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rate_limiter_blocks_excessive_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ]);
            // Either 401/422 on bad credentials, but not 429
            $this->assertNotEquals(429, $response->status());
        }

        // 6th attempt must be 429 Too Many Requests
        $sixthResponse = $this->postJson('/api/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ]);
        $sixthResponse->assertStatus(429);
    }

    public function test_rate_limiters_are_properly_configured(): void
    {
        $limiterLogin = RateLimiter::limiter('login');
        $this->assertNotNull($limiterLogin);

        $limiterApi = RateLimiter::limiter('api');
        $this->assertNotNull($limiterApi);

        $limiterSensitive = RateLimiter::limiter('sensitive');
        $this->assertNotNull($limiterSensitive);
    }
}
