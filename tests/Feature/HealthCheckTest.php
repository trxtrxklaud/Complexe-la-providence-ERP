<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_ok_when_database_is_reachable(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'db',
                'timestamp',
            ])
            ->assertJson([
                'status' => 'ok',
                'db' => 'ok',
            ]);
    }

    public function test_health_check_does_not_require_authentication(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200);
    }
}
