<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CacheApiResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin');
        $this->admin->update(['is_active' => true]);

        AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => '70.000',
            'ledger_category' => 'registration_fee',
            'is_active' => true,
        ]);
    }

    public function test_academic_years_endpoint_returns_cache_control_and_etag_headers(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/academic-years');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'max-age=1800, private, stale-while-revalidate=3600');
        $this->assertTrue($response->headers->has('ETag'));
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_academic_years_endpoint_returns_304_when_if_none_match_matches(): void
    {
        $firstResponse = $this->actingAs($this->admin)->getJson('/api/academic-years');
        $etag = $firstResponse->headers->get('ETag');

        $secondResponse = $this->actingAs($this->admin)->getJson('/api/academic-years', [
            'If-None-Match' => $etag,
        ]);

        $secondResponse->assertStatus(304);
        $secondResponse->assertHeader('ETag', $etag);
        $this->assertEmpty($secondResponse->getContent());
    }

    public function test_fee_types_endpoint_returns_cache_control_headers(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/fee-types');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'max-age=1800, private, stale-while-revalidate=3600');
        $this->assertTrue($response->headers->has('ETag'));
    }
}
