<?php

namespace Database\Seeders;

use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Services\ReportTemplateService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiseaseReportingSeeder extends Seeder
{
    private const AGE_GROUPS = ['<12 Months', '12-59 Months', '5-9 Years', '10-19 Years', '>19 Years'];

    public function run(): void
    {
        $hmb = Mda::query()
            ->where(function ($query): void {
                $query->where('code', 'like', '%HMB%')
                    ->orWhere('name', 'like', '%HMB%')
                    ->orWhere('name', 'like', '%Hospital Management Board%')
                    ->orWhere('name', 'like', '%Hospitals Management Board%');
            })
            ->orderBy('id')
            ->first();

        if (! $hmb) {
            $this->command?->warn('Disease report not seeded: Hospital Management Board MDA was not found.');

            return;
        }

        $actor = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'Super Admin'))->first();

        DB::transaction(function () use ($hmb, $actor): void {
            $template = ReportTemplate::query()->updateOrCreate(
                ['code' => 'HMB_MONTHLY_DISEASE_REPORT'],
                [
                    'owner_mda_id' => $hmb->id,
                    'module_id' => Module::query()->where('code', 'service_reporting')->value('id'),
                    'module_code' => 'service_reporting',
                    'name' => 'HMB Monthly Disease Report',
                    'description' => 'Monthly disease cases and deaths by age group, with inpatient and outpatient case totals.',
                    'frequency' => 'monthly',
                    'status' => 'active',
                    'requires_approval' => true,
                    'submission_deadline_day' => 7,
                    'allow_late_submission' => true,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ],
            );

            app(ReportTemplateService::class)->syncStructure($template, $this->sections());

            $stationIds = $hmb->stations()->pluck('id')->all();

            // Upsert only this MDA's assignments, leaving any later custom assignments intact.
            foreach ($stationIds ?: [null] as $stationId) {
                $template->assignments()->updateOrCreate(
                    ['mda_id' => $hmb->id, 'station_id' => $stationId, 'department_id' => null],
                    [
                        'is_required' => true,
                        'status' => 'active',
                        'assigned_by' => $actor?->id,
                        'assigned_at' => now(),
                    ],
                );
            }
        });
    }

    /** @return list<array<string, mixed>> */
    protected function sections(): array
    {
        return [
            $this->diseaseSection('IMMEDIATE_DISEASES', 'A. Immediate', 10, [
                ['cholera', '1. Cholera'],
                ['csm', '2. CSM'],
                ['lassa_fever', '3. Lassa Fever'],
                ['measles', '4. Measles'],
                ['yellow_fever', '5. Yellow Fever'],
                ['human_influenza', '6. Human Influenza'],
            ]),
            $this->diseaseSection('ROUTINE_DISEASES', 'B. Routine', 20, [
                ['dysentery', '7. Diarrhea with blood (Dysentery)'],
                ['gastroenteritis', '8. Diarrhea without blood (G.E)'],
                ['hepatitis_b', '9. Hepatitis B'],
                ['hiv_aids', '10. HIV/Aids'],
                ['leprosy', '11. Leprosy'],
                ['lymphatic_filariasis', '12. Lymphatic Filariasis'],
                ['malaria', '13. Malaria'],
                ['malaria_in_pregnancy', '14. Malaria in Pregnancy'],
                ['neonatal_tetanus', '15. Neonatal Tetanus'],
                ['tetanus', '16. Tetanus'],
                ['onchocerciasis', '17. Onchocerciasis'],
                ['oral_condition', '18. Oral Condition'],
                ['pertussis', '19. Pertussis'],
                ['plague', '20. Plague'],
                ['pneumonia', '21. Pneumonia'],
                ['poliomyelitis', '22. Poliomyelitis'],
                ['stis', '23. STIs'],
                ['tuberculosis', '24. Tuberculosis'],
                ['typhoid', '25. Typhoid'],
                ['eye_condition', '26. Eye Condition'],
            ]),
            $this->diseaseSection('NON_COMMUNICABLE_DISEASES', 'Non-Communicable Disease', 30, [
                ['accident_home', '145. Accident - Home'],
                ['accident_rta', '145. Accident - RTA'],
                ['accident_other', '145. Accident - Others'],
                ['anaemia', '146. Anaemia'],
                ['cancers', '147. Cancers'],
                ['snake_bite', '148. Snake bite'],
                ['pid', '149. PID'],
                ['ccf', '150. CCF'],
                ['diabetes_mellitus', '151. Diabetes Mellitus'],
                ['gopd_deficiency', '152. GOPD Deficiency'],
                ['hypertension', '153. Hypertension'],
                ['malnutrition', '154. Malnutrition'],
                ['mental_condition', '155. Mental Condition'],
                ['sickle_cell_disease', '156. Sickle Cell Disease'],
            ]),
        ];
    }

    /** @param list<array{0: string, 1: string}> $diseases */
    protected function diseaseSection(string $code, string $title, int $sortOrder, array $diseases): array
    {
        $indicators = [];

        foreach ($diseases as $index => [$diseaseCode, $label]) {
            $indicators[] = [
                ...$this->indicator($diseaseCode.'_cases', $label.' - Cases', ($index * 20) + 10),
                'help_text' => 'Inpatient and outpatient cases only. Totals exclude deaths.',
                'dimensions' => [
                    [
                        'dimension_key' => 'care_setting',
                        'dimension_label' => 'Cases',
                        'dimension_values' => ['In-patient', 'Out-patient'],
                        'is_required' => false,
                        'total_strategy' => 'sum_values',
                        'sort_order' => 10,
                    ],
                    $this->ageDimension(20),
                ],
            ];
            $indicators[] = [
                ...$this->indicator($diseaseCode.'_deaths', $label.' - Deaths', ($index * 20) + 20),
                'dimensions' => [$this->ageDimension(10)],
            ];
        }

        return [
            'code' => $code,
            'title' => $title,
            'sort_order' => $sortOrder,
            'indicators' => $indicators,
        ];
    }

    protected function indicator(string $code, string $label, int $sortOrder): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'value_type' => 'integer',
            'field_type' => 'whole_number',
            'is_required' => false,
            'validation_rules' => ['min' => 0],
            'sort_order' => $sortOrder,
            'status' => 'active',
        ];
    }

    protected function ageDimension(int $sortOrder): array
    {
        return [
            'dimension_key' => 'age_group',
            'dimension_label' => 'Age Group',
            'dimension_values' => self::AGE_GROUPS,
            'is_required' => false,
            'total_strategy' => 'sum_values',
            'sort_order' => $sortOrder,
        ];
    }
}
