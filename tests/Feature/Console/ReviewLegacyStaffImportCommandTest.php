<?php

namespace Tests\Feature\Console;

use App\Domain\Legacy\Services\LegacyStaffImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class ReviewLegacyStaffImportCommandTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLegacyStaffFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyStaffFixtures();
        parent::tearDown();
    }

    public function test_review_command_shows_batch_summary_and_issue_breakdowns(): void
    {
        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
            'publish' => true,
        ]);

        $this
            ->artisan('legacy:review-staff-import', ['--limit' => 5])
            ->expectsOutputToContain('Reviewing legacy staff import batch #1.')
            ->expectsOutputToContain('missing_station')
            ->expectsOutputToContain('Call Allowances Resolved')
            ->assertSuccessful();
    }

    public function test_review_command_can_filter_to_a_specific_issue_code(): void
    {
        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
            'publish' => true,
        ]);

        $this
            ->artisan('legacy:review-staff-import', [
                '--limit' => 5,
                '--issue-code' => 'edor_mismatch',
            ])
            ->expectsOutputToContain('Reviewing legacy staff import batch #1.')
            ->expectsOutputToContain('edor_mismatch')
            ->assertSuccessful();
    }
}
