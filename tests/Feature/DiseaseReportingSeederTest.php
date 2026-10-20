<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\ServiceReporting\Models\ReportSubmissionValue;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Models\User;
use Database\Seeders\DiseaseReportingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiseaseReportingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_has_all_diseases_and_separate_case_and_death_dimensions(): void
    {
        [$hmb, $moh] = $this->fixtures();
        $this->seed(DiseaseReportingSeeder::class);
        $template = $this->template();

        $this->assertSame($hmb->id, $template->owner_mda_id);
        $this->assertSame('monthly', $template->frequency);
        $this->assertSame('active', $template->status);
        $this->assertTrue($template->requires_approval);
        $this->assertSame(['IMMEDIATE_DISEASES', 'ROUTINE_DISEASES', 'NON_COMMUNICABLE_DISEASES'], $template->sections->pluck('code')->all());
        $this->assertSame([12, 40, 28], $template->sections->map(fn ($section) => $section->indicators->count())->all());

        $expectedCodes = [
            'cholera', 'csm', 'lassa_fever', 'measles', 'yellow_fever', 'human_influenza',
            'dysentery', 'gastroenteritis', 'hepatitis_b', 'hiv_aids', 'leprosy', 'lymphatic_filariasis',
            'malaria', 'malaria_in_pregnancy', 'neonatal_tetanus', 'tetanus', 'onchocerciasis',
            'oral_condition', 'pertussis', 'plague', 'pneumonia', 'poliomyelitis', 'stis',
            'tuberculosis', 'typhoid', 'eye_condition', 'accident_home', 'accident_rta', 'accident_other',
            'anaemia', 'cancers', 'snake_bite', 'pid', 'ccf', 'diabetes_mellitus', 'gopd_deficiency',
            'hypertension', 'malnutrition', 'mental_condition', 'sickle_cell_disease',
        ];
        $indicators = $template->sections->flatMap->indicators->keyBy('code');
        $this->assertCount(80, $indicators);

        foreach ($expectedCodes as $code) {
            $cases = $indicators->get($code.'_cases');
            $deaths = $indicators->get($code.'_deaths');
            $this->assertNotNull($cases, $code);
            $this->assertNotNull($deaths, $code);
            $this->assertSame(['care_setting', 'age_group'], $cases->dimensions->pluck('dimension_key')->all());
            $this->assertSame(['In-patient', 'Out-patient'], $cases->dimensions[0]->dimension_values);
            $this->assertSame(['age_group'], $deaths->dimensions->pluck('dimension_key')->all());

            foreach ([$cases, $deaths] as $indicator) {
                $this->assertSame('integer', $indicator->value_type);
                $this->assertSame('whole_number', $indicator->field_type);
                $this->assertSame(['min' => 0], $indicator->validation_rules);
                $this->assertSame(
                    ['<12 Months', '12-59 Months', '5-9 Years', '10-19 Years', '>19 Years'],
                    $indicator->dimensions->firstWhere('dimension_key', 'age_group')->dimension_values,
                );
                foreach ($indicator->dimensions as $dimension) {
                    $this->assertSame('sum_values', $dimension->total_strategy);
                    $this->assertNotContains('Total', $dimension->dimension_values);
                }
            }
        }

        $this->assertSame([145, 146, 147, 148, 149, 150, 151, 152, 153, 154, 155, 156], $template->sections[2]->indicators
            ->map(fn ($indicator) => (int) $indicator->label)->unique()->values()->all());
        $this->assertSame(2, $template->assignments()->count());
        $this->assertSame([$hmb->id], $template->assignments()->pluck('mda_id')->unique()->values()->all());
        $this->assertFalse($template->assignments()->where('mda_id', $moh->id)->exists());
    }

    public function test_rerunning_seeder_keeps_structure_ids_and_custom_assignments(): void
    {
        [, $moh] = $this->fixtures();
        $this->seed(DiseaseReportingSeeder::class);
        $template = $this->template();
        $sectionIds = $template->sections->pluck('id')->all();
        $indicatorIds = $template->sections->flatMap->indicators->pluck('id')->all();
        $assignmentIds = $template->assignments()->pluck('id')->all();
        $custom = $template->assignments()->create(['mda_id' => $moh->id, 'status' => 'active', 'is_required' => false]);

        $this->seed(DiseaseReportingSeeder::class);
        $fresh = $this->template();
        $this->assertSame($template->id, $fresh->id);
        $this->assertSame($sectionIds, $fresh->sections->pluck('id')->all());
        $this->assertSame($indicatorIds, $fresh->sections->flatMap->indicators->pluck('id')->all());
        $this->assertSame([...$assignmentIds, $custom->id], $fresh->assignments()->orderBy('id')->pluck('id')->all());
        $this->assertSame('active', $custom->fresh()->status);
        $this->assertFalse($custom->fresh()->is_required);
    }

    public function test_stationless_hmb_gets_one_mda_assignment_without_an_actor(): void
    {
        $hmb = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $this->seed(DiseaseReportingSeeder::class);
        $this->seed(DiseaseReportingSeeder::class);
        $template = $this->template();

        $this->assertSame(1, $template->assignments()->count());
        $this->assertDatabaseHas('report_template_assignments', [
            'report_template_id' => $template->id,
            'mda_id' => $hmb->id,
            'station_id' => null,
            'status' => 'active',
            'is_required' => true,
        ]);
    }

    public function test_seeder_does_not_assign_the_template_to_an_unrelated_mda_when_hmb_is_missing(): void
    {
        Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $this->seed(DiseaseReportingSeeder::class);
        $this->assertDatabaseMissing('report_templates', ['code' => 'HMB_MONTHLY_DISEASE_REPORT']);
    }

    public function test_case_and_death_values_survive_reseeding_and_report_separately_across_periods(): void
    {
        [$hmb] = $this->fixtures();
        $user = User::factory()->mdaUser($hmb)->create();
        $user->assignRole('MDA Admin');
        $user->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $hmb->id]);
        $this->seed(DiseaseReportingSeeder::class);
        $template = $this->template();
        $station = $hmb->stations()->firstOrFail();

        foreach (['2026-01', '2026-02'] as $period) {
            $id = $this->actingAs($user)->postJson(route('api.service-reports.submissions.store'), [
                'template_id' => $template->id,
                'mda_id' => $hmb->id,
                'station_id' => $station->id,
                'period' => $period,
            ])->assertCreated()->json('data.id');

            $this->actingAs($user)->putJson(route('api.service-reports.submissions.draft', $id), [
                'values' => [
                    ['indicator_code' => 'cholera_cases', 'cells' => [
                        ['dimensions' => ['care_setting' => 'In-patient', 'age_group' => '<12 Months'], 'value' => 3],
                        ['dimensions' => ['care_setting' => 'Out-patient', 'age_group' => '>19 Years'], 'value' => 7],
                    ]],
                    ['indicator_code' => 'cholera_deaths', 'dimensions' => ['age_group' => ['<12 Months' => 1, '>19 Years' => 2]]],
                ],
            ])->assertOk()->assertJsonPath('data.summary.cholera_cases', 10)->assertJsonPath('data.summary.cholera_deaths', 3);

            $this->actingAs($user)->postJson(route('api.service-reports.submissions.submit', $id))->assertOk();
            $this->actingAs($user)->postJson(route('api.service-reports.submissions.approve', $id))->assertOk();
            $this->seed(DiseaseReportingSeeder::class);
            $this->assertSame(4, ReportSubmissionValue::query()->where('report_submission_id', $id)->count());
        }

        $this->actingAs($user)->getJson(route('api.service-reports.analytics.trends', [
            'template_code' => $template->code,
            'indicator_codes' => ['cholera_cases', 'cholera_deaths'],
            'from' => '2026-01',
            'to' => '2026-02',
            'mda_id' => $hmb->id,
            'status' => 'approved,locked',
        ]))->assertOk()
            ->assertJsonPath('data.indicators.0.totals.grand_total', 20)
            ->assertJsonPath('data.indicators.1.totals.grand_total', 6);
    }

    protected function fixtures(): array
    {
        $hmb = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $moh = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        foreach (['GHM', 'GHS'] as $code) {
            Station::query()->create(['mda_id' => $hmb->id, 'code' => $code, 'name' => $code, 'status' => 'active']);
        }
        $this->seed(RolesAndPermissionsSeeder::class);

        return [$hmb, $moh];
    }

    protected function template(): ReportTemplate
    {
        return ReportTemplate::query()->where('code', 'HMB_MONTHLY_DISEASE_REPORT')
            ->with('sections.indicators.dimensions')->firstOrFail();
    }
}
