<?php

namespace Tests\Feature;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\ServiceReporting\Models\ReportSubmission;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServiceReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hmb_monthly_statistics_template_is_seeded_for_hmb_only(): void
    {
        [$hmb, $moh] = $this->seedFixtures();

        $this->assertDatabaseHas('report_templates', [
            'code' => 'HMB_MONTHLY_STATISTICS',
            'owner_mda_id' => $hmb->id,
            'status' => 'active',
        ]);
        $this->assertTrue($this->mdaHasModule($hmb, 'service_reporting'));
        $this->assertFalse($this->mdaHasModule($moh, 'service_reporting'));
    }

    public function test_authorized_user_can_create_generic_template_with_structure(): void
    {
        [$hmb] = $this->seedFixtures();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)
            ->postJson(route('api.service-reports.templates.store'), [
                'owner_mda_id' => $hmb->id,
                'name' => 'HMB Pharmacy Return',
                'code' => 'HMB_PHARMACY_RETURN',
                'frequency' => 'monthly',
                'status' => 'draft',
                'sections' => [[
                    'title' => 'Drug Availability',
                    'code' => 'DRUG_AVAILABILITY',
                    'indicators' => [[
                        'code' => 'stockout_count',
                        'label' => 'Stockout Count',
                        'value_type' => 'integer',
                        'is_required' => true,
                    ]],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sections.0.indicators.0.code', 'stockout_count');
    }

    public function test_non_hmb_mda_cannot_see_hmb_template_unless_assigned(): void
    {
        [, $moh] = $this->seedFixtures();
        $mohUser = $this->makeMdaAdmin($moh);

        $this->actingAs($mohUser)
            ->getJson(route('api.service-reports.templates.index'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_assign_a_template_to_an_mda(): void
    {
        [$hmb] = $this->seedFixtures();
        $admin = $this->makePlatformAdmin();
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('api.service-reports.assignments.update', $template), [
                'assignments' => [[
                    'mda_id' => $hmb->id,
                    'station_id' => null,
                    'department_id' => null,
                    'is_required' => true,
                    'status' => 'active',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.mda_id', $hmb->id)
            ->assertJsonPath('data.0.status', 'active');

        $this->assertDatabaseHas('report_template_assignments', [
            'report_template_id' => $template->id,
            'mda_id' => $hmb->id,
            'station_id' => null,
            'department_id' => null,
            'status' => 'active',
        ]);
    }

    public function test_submission_values_totals_workflow_and_locking(): void
    {
        [$hmb] = $this->seedFixtures();
        $user = $this->makeMdaAdmin($hmb);
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();
        $station = Station::query()->where('mda_id', $hmb->id)->firstOrFail();

        $draftId = $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.store'), [
                'template_id' => $template->id,
                'mda_id' => $hmb->id,
                'station_id' => $station->id,
                'period' => '2026-01',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user)
            ->putJson(route('api.service-reports.submissions.draft', $draftId), [
                'values' => $this->values(14),
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.new_outpatient_attendance', 12);

        $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.submit', $draftId), [])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.approve', $draftId), [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($user)
            ->putJson(route('api.service-reports.submissions.draft', $draftId), [
                'values' => $this->values(20),
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.lock', $draftId), [])
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->assertDatabaseHas('report_submission_reviews', [
            'report_submission_id' => $draftId,
            'action' => 'locked',
        ]);
    }

    public function test_required_values_are_enforced_on_submit(): void
    {
        [$hmb] = $this->seedFixtures();
        $user = $this->makeMdaAdmin($hmb);
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();
        $station = Station::query()->where('mda_id', $hmb->id)->firstOrFail();

        $draftId = $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.store'), [
                'template_id' => $template->id,
                'mda_id' => $hmb->id,
                'station_id' => $station->id,
                'period' => '2026-02',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.submit', $draftId), [])
            ->assertUnprocessable();
    }

    public function test_hiv_positive_analytics_returns_two_year_totals(): void
    {
        [$hmb] = $this->seedFixtures();
        $user = $this->makeMdaAdmin($hmb);
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();
        $station = Station::query()->where('mda_id', $hmb->id)->firstOrFail();

        foreach (['2024-01' => 100, '2024-02' => 120, '2025-01' => 140] as $period => $hivPositive) {
            $this->createApprovedSubmission($user, $template, $hmb, $station, $period, $hivPositive);
        }

        $this->actingAs($user)
            ->getJson(route('api.service-reports.analytics.trends', [
                'template_code' => 'HMB_MONTHLY_STATISTICS',
                'indicator_code' => 'hiv_positive',
                'from' => '2024-01',
                'to' => '2025-12',
                'mda_id' => $hmb->id,
                'status' => 'approved,locked',
            ]))
            ->assertOk()
            ->assertJsonPath('data.totals.grand_total', 360)
            ->assertJsonPath('data.by_year.0.value', 220)
            ->assertJsonPath('data.by_year.1.value', 140);
    }

    public function test_analytics_can_return_multiple_indicators_without_combining_their_totals(): void
    {
        [$hmb] = $this->seedFixtures();
        $user = $this->makeMdaAdmin($hmb);
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();
        $station = Station::query()->where('mda_id', $hmb->id)->firstOrFail();

        $this->createApprovedSubmission($user, $template, $hmb, $station, '2026-01', 18);

        $this->actingAs($user)
            ->getJson(route('api.service-reports.analytics.trends', [
                'template_code' => 'HMB_MONTHLY_STATISTICS',
                'indicator_codes' => ['new_outpatient_attendance', 'hiv_positive'],
                'from' => '2026-01',
                'to' => '2026-01',
                'mda_id' => $hmb->id,
                'status' => 'approved,locked',
            ]))
            ->assertOk()
            ->assertJsonPath('data.indicators.0.indicator.code', 'new_outpatient_attendance')
            ->assertJsonPath('data.indicators.0.totals.grand_total', 12)
            ->assertJsonPath('data.indicators.1.indicator.code', 'hiv_positive')
            ->assertJsonPath('data.indicators.1.totals.grand_total', 18);
    }

    public function test_template_table_analytics_returns_all_template_sections_for_selected_months(): void
    {
        [$hmb] = $this->seedFixtures();
        $user = $this->makeMdaAdmin($hmb);
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();
        $station = Station::query()->where('mda_id', $hmb->id)->firstOrFail();

        $this->createApprovedSubmission($user, $template, $hmb, $station, '2026-01', 18);

        $this->actingAs($user)
            ->getJson(route('api.service-reports.analytics.template-table', [
                'template_code' => 'HMB_MONTHLY_STATISTICS',
                'report_style' => 'template_table',
                'from' => '2026-01',
                'to' => '2026-02',
                'mda_id' => $hmb->id,
                'status' => 'approved,locked',
            ]))
            ->assertOk()
            ->assertJsonPath('data.template.code', 'HMB_MONTHLY_STATISTICS')
            ->assertJsonPath('data.periods.0.key', '2026-01')
            ->assertJsonPath('data.periods.1.key', '2026-02')
            ->assertJsonPath('data.sections.0.title', 'Facility Identification');
    }

    public function test_station_scoped_user_is_limited_to_assigned_station_for_reporting(): void
    {
        [$hmb] = $this->seedFixtures();
        $template = ReportTemplate::query()->where('code', 'HMB_MONTHLY_STATISTICS')->firstOrFail();
        $stations = Station::query()->where('mda_id', $hmb->id)->orderBy('id')->get();
        $assignedStation = $stations[0];
        $otherStation = $stations[1];
        $stationScopedUser = $this->makeMdaAdmin($hmb, $assignedStation);
        $mdaUser = $this->makeMdaAdmin($hmb);

        $draftId = $this->actingAs($stationScopedUser)
            ->postJson(route('api.service-reports.submissions.store'), [
                'template_id' => $template->id,
                'mda_id' => $hmb->id,
                'period' => '2026-03',
            ])
            ->assertCreated()
            ->assertJsonPath('data.station_id', $assignedStation->id)
            ->json('data.id');

        $this->actingAs($stationScopedUser)
            ->postJson(route('api.service-reports.submissions.store'), [
                'template_id' => $template->id,
                'mda_id' => $hmb->id,
                'station_id' => $otherStation->id,
                'period' => '2026-04',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station_id');

        $this->actingAs($stationScopedUser)
            ->putJson(route('api.service-reports.submissions.draft', $draftId), [
                'values' => $this->values(16),
            ])
            ->assertOk();

        $this->actingAs($stationScopedUser)
            ->postJson(route('api.service-reports.submissions.submit', $draftId), [])
            ->assertOk();

        $otherSubmissionId = $this->createApprovedSubmission($mdaUser, $template, $hmb, $otherStation, '2026-05', 22);

        $this->actingAs($stationScopedUser)
            ->getJson(route('api.service-reports.submissions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draftId);

        $this->actingAs($stationScopedUser)
            ->getJson(route('api.service-reports.submissions.show', $otherSubmissionId))
            ->assertForbidden();
    }

    protected function createApprovedSubmission(User $user, ReportTemplate $template, Mda $mda, Station $station, string $period, int $hivPositive): int
    {
        $id = $this->actingAs($user)
            ->postJson(route('api.service-reports.submissions.store'), [
                'template_id' => $template->id,
                'mda_id' => $mda->id,
                'station_id' => $station->id,
                'period' => $period,
            ])
            ->json('data.id');

        $this->actingAs($user)->putJson(route('api.service-reports.submissions.draft', $id), ['values' => $this->values($hivPositive)]);
        $this->actingAs($user)->postJson(route('api.service-reports.submissions.submit', $id));
        $this->actingAs($user)->postJson(route('api.service-reports.submissions.approve', $id));

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function values(int $hivPositive): array
    {
        return [
            ['indicator_code' => 'new_outpatient_attendance', 'dimensions' => ['sex' => ['male' => 5, 'female' => 7]]],
            ['indicator_code' => 'old_outpatient_attendance', 'dimensions' => ['sex' => ['male' => 3, 'female' => 4]]],
            ['indicator_code' => 'hiv_positive', 'value' => $hivPositive],
        ];
    }

    /**
     * @return array{0:Mda,1:Mda}
     */
    protected function seedFixtures(): array
    {
        $hmb = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $moh = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        Station::query()->create(['mda_id' => $hmb->id, 'code' => 'GHM', 'name' => 'General Hospital Minna', 'status' => 'active']);
        Station::query()->create(['mda_id' => $hmb->id, 'code' => 'GHS', 'name' => 'General Hospital Suleja', 'status' => 'active']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ServiceReportingSeeder::class);

        return [$hmb, $moh];
    }

    protected function makeMdaAdmin(Mda $mda, ?Station $station = null): User
    {
        $user = User::factory()->mdaUser($mda)->create([
            'station_id' => $station?->id,
        ]);
        $user->assignRole('MDA Admin');
        $user->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mda->id]);

        return $user;
    }

    protected function makePlatformAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'platform',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);

        return $user;
    }

    protected function mdaHasModule(Mda $mda, string $moduleCode): bool
    {
        return MdaModule::query()
            ->where('mda_id', $mda->id)
            ->where('enabled', true)
            ->where('module_id', Module::query()->where('code', $moduleCode)->value('id'))
            ->exists();
    }
}
