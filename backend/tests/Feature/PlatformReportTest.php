<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformReportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->firstOrFail();
    }

    public function test_stats_count_the_seeded_curriculum(): void
    {
        $stats = app(PlatformReportService::class)->stats();

        $this->assertGreaterThan(0, $stats['grades']);
        $this->assertGreaterThan(0, $stats['subjects']);
        $this->assertGreaterThan(0, $stats['textbooks']);
        $this->assertGreaterThan(0, $stats['lessons']);
        $this->assertGreaterThan(0, $stats['users']);
    }

    public function test_coverage_lists_every_grade_with_counts(): void
    {
        $coverage = app(PlatformReportService::class)->coverage();

        $this->assertNotEmpty($coverage);
        $this->assertArrayHasKey('name', $coverage[0]);
        $this->assertArrayHasKey('lessons', $coverage[0]);
        $this->assertGreaterThan(0, array_sum(array_column($coverage, 'lessons')));
    }

    public function test_owner_can_read_the_report_summary(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/v1/owner/platform-report')
            ->assertOk()
            ->assertJsonStructure(['stats' => ['grades', 'lessons', 'users'], 'coverage']);
    }

    public function test_owner_can_download_the_report_as_pdf(): void
    {
        $response = $this->actingAs($this->owner())
            ->get('/api/v1/owner/platform-report/download');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_non_owner_cannot_download_the_report(): void
    {
        $student = User::where('email', 'student@school.ye')->firstOrFail();

        $this->actingAs($student)->getJson('/api/v1/owner/platform-report')->assertForbidden();
        $this->actingAs($student)->get('/api/v1/owner/platform-report/download')->assertForbidden();
    }
}
