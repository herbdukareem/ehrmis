<?php

namespace Database\Seeders;

use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Services\ReportTemplateAssignmentService;
use App\Domain\ServiceReporting\Services\ReportTemplateService;
use App\Models\User;
use Illuminate\Database\Seeder;

class ServiceReportingSeeder extends Seeder
{
    public function run(): void
    {
        $hmb = Mda::query()
            ->where(function ($query): void {
                $query
                    ->where('code', 'like', '%HMB%')
                    ->orWhere('name', 'like', '%HMB%')
                    ->orWhere('name', 'like', '%Hospital Management Board%')
                    ->orWhere('name', 'like', '%Hospitals Management Board%');
            })
            ->orderBy('id')
            ->first();

        if (! $hmb) {
            return;
        }

        $actor = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'Super Admin'))->first()
            ?? User::query()->orderBy('id')->first();

        $module = Module::query()->where('code', 'service_reporting')->first();

        $template = ReportTemplate::query()->updateOrCreate(
            ['code' => 'HMB_MONTHLY_STATISTICS'],
            [
                'owner_mda_id' => $hmb->id,
                'module_id' => $module?->id,
                'module_code' => 'service_reporting',
                'name' => 'HMB Monthly Statistics Report',
                'description' => 'Monthly structured service statistics returns for HMB facilities.',
                'frequency' => 'monthly',
                'status' => 'active',
                'requires_approval' => true,
                'submission_deadline_day' => 7,
                'allow_late_submission' => true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ],
        );

        app(ReportTemplateService::class)->syncStructure($template, $this->hmbMonthlyStatisticsSections());

        $hctTemplate = ReportTemplate::query()->updateOrCreate(
            ['code' => 'HCT_MONTHLY_REPORT'],
            [
                'owner_mda_id' => $hmb->id,
                'module_id' => $module?->id,
                'module_code' => 'service_reporting',
                'name' => 'HCT Monthly Report',
                'description' => 'HIV counselling and testing return excluding women attending ANC.',
                'frequency' => 'monthly',
                'status' => 'active',
                'requires_approval' => true,
                'submission_deadline_day' => 7,
                'allow_late_submission' => true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ],
        );

        app(ReportTemplateService::class)->syncStructure($hctTemplate, $this->hctMonthlyReportSections());

        $assignments = $hmb->stations()->exists()
            ? $hmb->stations()->get()->map(fn ($station): array => [
                'mda_id' => $hmb->id,
                'station_id' => $station->id,
                'is_required' => true,
                'status' => 'active',
            ])->all()
            : [[
                'mda_id' => $hmb->id,
                'station_id' => null,
                'is_required' => true,
                'status' => 'active',
            ]];

        if ($actor) {
            foreach ([$template, $hctTemplate] as $reportTemplate) {
                app(ReportTemplateAssignmentService::class)->syncAssignments($reportTemplate->fresh(), $assignments, $actor);
            }
        } else {
            foreach ([$template, $hctTemplate] as $reportTemplate) {
                foreach ($assignments as $assignment) {
                    $reportTemplate->assignments()->updateOrCreate(
                        [
                            'mda_id' => $assignment['mda_id'],
                            'station_id' => $assignment['station_id'],
                        ],
                        [
                            'is_required' => true,
                            'status' => 'active',
                            'assigned_at' => now(),
                        ],
                    );
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function hmbMonthlyStatisticsSections(): array
    {
        return [
            [
                'title' => 'Facility Identification',
                'code' => 'FACILITY_IDENTIFICATION',
                'sort_order' => 10,
                'indicators' => [
                    $this->indicator('facility_name', 'Facility Name', 'text', false, 10),
                    $this->indicator('reporting_month', 'Reporting Month', 'text', false, 20),
                    $this->indicator('reporting_year', 'Reporting Year', 'integer', false, 30),
                ],
            ],
            [
                'title' => 'Outpatient Attendance',
                'code' => 'OUTPATIENT_ATTENDANCE',
                'sort_order' => 20,
                'indicators' => [
                    $this->sexIndicator('new_outpatient_attendance', 'New Outpatient Attendance', true, 10),
                    $this->sexIndicator('old_outpatient_attendance', 'Old Outpatient Attendance', true, 20),
                    $this->sexIndicator('general_outpatient_attendance', 'General Out Patient Attendance', false, 30),
                    $this->sexIndicator('gopd_outpatient_attendance', 'GoPD Out Patient Attendance', false, 40),
                    $this->sexIndicator('new_adult_outpatient_attendance', 'New Adult Out Patient Attendance', false, 50),
                    $this->sexIndicator('old_adult_outpatient_attendance', 'Old Adult Out Patient Attendance', false, 60),
                    $this->sexIndicator('new_pediatric_outpatient_attendance', 'New Pediatric Out Patient Attendance', false, 70),
                    $this->sexIndicator('old_pediatric_outpatient_attendance', 'Old Pediatric Out Patient Attendance', false, 80),
                    $this->sexIndicator('dental_attendance_new', 'Dental Attendance New', false, 90),
                    $this->sexIndicator('dental_attendance_old', 'Dental Attendance Old', false, 100),
                    $this->sexIndicator('eye_attendance_new', 'Eye Attendance New', false, 110),
                    $this->sexIndicator('eye_attendance_old', 'Eye Attendance Old', false, 120),
                    $this->sexIndicator('road_traffic_accident_cases', 'RTA', false, 130),
                    $this->sexIndicator('xray_cases', 'X-ray', false, 140),
                    $this->sexIndicator('scanning_cases', 'Scanning', false, 150),
                ],
            ],
            [
                'title' => 'Inpatient Statistics',
                'code' => 'INPATIENT_STATISTICS',
                'sort_order' => 30,
                'indicators' => [
                    $this->sexIndicator('inpatient_admission', 'Admission', false, 10),
                    $this->sexIndicator('inpatient_discharge', 'Discharge', false, 20),
                    $this->sexIndicator('inpatient_death', 'Death', false, 30),
                    $this->sexIndicator('bed_complement', 'Bed Compliment', false, 40),
                    $this->sexIndicator('patient_days', 'Patient Days', false, 50),
                    $this->sexIndicator('bed_occupancy_rate', '% Occupancy', false, 60, 'percentage'),
                    $this->sexIndicator('average_length_of_stay', 'Average Length of Stay', false, 70, 'decimal'),
                    $this->sexIndicator('turnover_interval', 'Turn Over Interval', false, 80, 'decimal'),
                    $this->sexIndicator('vacant_bed_days', 'Vacant Bed Days', false, 90),
                ],
            ],
            [
                'title' => 'Maternal Statistics',
                'code' => 'MATERNAL_STATISTICS',
                'sort_order' => 40,
                'indicators' => [
                    $this->indicator('normal_delivery', 'ND', 'integer', false, 10),
                    $this->indicator('maternal_caesarean_section', 'C/S', 'integer', false, 20),
                    $this->sexIndicator('maternal_live_births', 'Live Birth', false, 30),
                    $this->indicator('maternal_still_births', 'Still Birth', 'integer', false, 40),
                    $this->indicator('triplet_deliveries', 'Triplets +++', 'integer', false, 50),
                    $this->indicator('antepartum_haemorrhage', 'APH +++', 'integer', false, 60),
                    $this->indicator('breech_delivery', 'Breach Delivery ++', 'integer', false, 70),
                    $this->indicator('premature_delivery', 'Premature +++', 'integer', false, 80),
                    $this->indicator('ruptured_uterus', 'Ruptured Uterus ++', 'integer', false, 90),
                    $this->indicator('twin_delivery', 'Twin Delivery +++', 'integer', false, 100),
                    $this->indicator('maternal_death', 'Maternal Death', 'integer', false, 110),
                    $this->indicator('postpartum_haemorrhage', 'PPH +++', 'integer', false, 120),
                    $this->indicator('abortion', 'Abortion ++', 'integer', false, 130),
                    $this->indicator('pregnancy_induced_hypertension', 'PIH +++', 'integer', false, 140),
                    $this->indicator('eclampsia', 'Eclapsia +++', 'integer', false, 150),
                    $this->indicator('born_before_arrival', 'BBA ++++', 'integer', false, 160),
                ],
            ],
            [
                'title' => 'Total Delivery',
                'code' => 'TOTAL_DELIVERY',
                'sort_order' => 50,
                'indicators' => [
                    $this->indicator('total_deliveries', 'Total Deliveries', 'integer', false, 10),
                    $this->indicator('total_hiv_tested', 'Total HIV Tested', 'integer', false, 20),
                    $this->indicator('hiv_positive', 'HIV Positive', 'integer', false, 30),
                    $this->indicator('hiv_negative', 'HIV Negative', 'integer', false, 40),
                    $this->indicator('hiv_counselling', 'HIV Counseling', 'integer', false, 50),
                    $this->indicator('gynaecology_attendance', 'Gynaecology Attendance', 'integer', false, 60),
                ],
            ],
            [
                'title' => 'Medical Outpatient Attendance',
                'code' => 'MEDICAL_OUTPATIENT_ATTENDANCE',
                'sort_order' => 60,
                'indicators' => [
                    $this->indicator('antenatal_attendance', 'Ante-Natal Attendance', 'integer', false, 10),
                    $this->sexIndicator('medical_outpatient_new', 'Medical Out Patient ATT, N', false, 20),
                    $this->sexIndicator('medical_outpatient_old', 'Medical Out Patient ATT, O', false, 30),
                    $this->sexIndicator('hypertension_new', 'Hypertension New', false, 40),
                    $this->sexIndicator('hypertension_old', 'Hypertension Old', false, 50),
                    $this->sexIndicator('hypertension_treated', 'Hypertension Treated', false, 60),
                    $this->sexIndicator('diabetes_new', 'Diabetes New', false, 70),
                    $this->sexIndicator('diabetes_old', 'Diabetes Old', false, 80),
                    $this->sexIndicator('diabetes_treated', 'Diabetes Treated', false, 90),
                    $this->sexIndicator('prostate_cancer_new', 'Prostate Cancer New', false, 100),
                    $this->sexIndicator('prostate_cancer_old', 'Prostate Cancer Old', false, 110),
                    $this->sexIndicator('prostate_cancer_treated', 'Prostate Cancer Treated', false, 120),
                    $this->sexIndicator('breast_cancer_new', 'Breast Cancer New', false, 130),
                    $this->sexIndicator('breast_cancer_old', 'Breast Cancer Old', false, 140),
                    $this->sexIndicator('breast_cancer_treated', 'Breast Cancer Treated', false, 150),
                    $this->indicator('cervical_cancer_new', 'Cervical Cancer New', 'integer', false, 160),
                    $this->indicator('cervical_cancer_old', 'Cervical Cancer Old', 'integer', false, 170),
                    $this->indicator('cervical_cancer_treated', 'Cervical Cancer Treated', 'integer', false, 180),
                    $this->indicator('cervical_cancer_deaths', 'No. of Death from Cervical Cancer', 'integer', false, 190),
                    $this->sexIndicator('sickle_cell_diseases_new', 'Sickle Cell Diseases New', false, 200),
                    $this->sexIndicator('sickle_cell_diseases_old', 'Sickle Cell Diseases Old', false, 210),
                    $this->sexIndicator('sickle_cell_diseases_death', 'Sickle Cell Diseases Death', false, 220),
                ],
            ],
            [
                'title' => 'Surgical Outpatient Attendance',
                'code' => 'SURGICAL_OUTPATIENT_ATTENDANCE',
                'sort_order' => 70,
                'indicators' => [
                    $this->sexIndicator('surgical_outpatient_new', 'Surgical Out Patient ATT, N', false, 10),
                    $this->sexIndicator('surgical_outpatient_old', 'Surgical Out Patient ATT, O', false, 20),
                ],
            ],
            [
                'title' => 'ENT Attendance',
                'code' => 'ENT_ATTENDANCE',
                'sort_order' => 80,
                'indicators' => [
                    $this->sexIndicator('ent_attendance_new', 'Ear, Nose and Throat, N', false, 10),
                    $this->sexIndicator('ent_attendance_old', 'Ear, Nose and Throat, O', false, 20),
                ],
            ],
            [
                'title' => 'Family Planning Attendance',
                'code' => 'FAMILY_PLANNING_ATTENDANCE',
                'sort_order' => 90,
                'indicators' => [
                    $this->newOldIndicator('family_planning_acceptor', 'Acceptor', false, 10),
                    $this->newOldIndicator('family_planning_oral_pill', 'Oral Pill', false, 20),
                    $this->newOldIndicator('family_planning_injection', 'Injection', false, 30),
                    $this->newOldIndicator('family_planning_implants', 'Implants', false, 40),
                    $this->newOldIndicator('family_planning_iucds', 'IUCDs', false, 50),
                    $this->newOldIndicator('family_planning_barriers', 'Barriers', false, 60),
                    $this->newOldIndicator('family_planning_sterilization', 'Sterilization', false, 70),
                    $this->newOldIndicator('family_planning_other', 'Other', false, 80),
                ],
            ],
            [
                'title' => 'Laboratory Services',
                'code' => 'LABORATORY_SERVICES',
                'sort_order' => 100,
                'indicators' => [
                    $this->indicator('fbs_fasting_blood_sugar', 'FBS (Fasting Blood Sugar)', 'integer', false, 10),
                    $this->indicator('rs_random_sugar', 'RS (Random Sugar)', 'integer', false, 20),
                    $this->indicator('stool_microscopy', 'Stool Microscopy', 'integer', false, 30),
                    $this->indicator('urea', 'Urea', 'integer', false, 40),
                    $this->indicator('cholesterol', 'Cholesterol', 'integer', false, 50),
                    $this->indicator('electrolysis', 'Electrolysis', 'integer', false, 60),
                    $this->indicator('creaturine', 'Creaturine', 'integer', false, 70),
                    $this->indicator('total_protein', 'Total Protein', 'integer', false, 80),
                    $this->indicator('alkaline_phosphate', 'Alkaline Phosphate', 'integer', false, 90),
                    $this->indicator('urine_microscopy', 'Urine Microscopy', 'integer', false, 100),
                    $this->indicator('blood_grouping', 'Blood Grouping', 'integer', false, 110),
                    $this->indicator('urine_culture', 'Urine Culture', 'integer', false, 120),
                    $this->indicator('west_film', 'West Film', 'integer', false, 130),
                    $this->indicator('stool_culture', 'Stool Culture', 'integer', false, 140),
                    $this->indicator('genotype', 'Genotype', 'integer', false, 150),
                    $this->indicator('sputum_afb', 'Sputum AFB', 'integer', false, 160),
                    $this->indicator('wound_culture', 'Wound Culture', 'integer', false, 170),
                    $this->indicator('semen_analysis', 'Semen Analysis', 'integer', false, 180),
                    $this->indicator('blood_culture', 'Blood Culture', 'integer', false, 190),
                    $this->indicator('hb_pcv', 'HB (PCV)', 'integer', false, 200),
                    $this->indicator('wbc', 'WBC', 'integer', false, 210),
                    $this->indicator('skin_snip', 'Skin Snip', 'integer', false, 220),
                    $this->indicator('sickling_test', 'Sickling Test', 'integer', false, 230),
                    $this->indicator('mp', 'MP', 'integer', false, 240),
                    $this->indicator('pregnancy_test', 'Pregnancy Test', 'integer', false, 250),
                    $this->indicator('esr', 'ESR', 'integer', false, 260),
                ],
            ],
            [
                'title' => 'Operations',
                'code' => 'OPERATIONS',
                'sort_order' => 110,
                'indicators' => [
                    $this->indicator('major_operations', 'Major', 'integer', false, 10),
                    $this->indicator('minor_operations', 'Minor', 'integer', false, 20),
                    $this->indicator('nhis_attendance', 'NHIS Attendance', 'integer', false, 30),
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function hctMonthlyReportSections(): array
    {
        return [
            [
                'title' => 'HIV Testing by Age, Sex and Service Point',
                'code' => 'HIV_TESTING_AGE_SEX_SERVICE_POINT',
                'description' => 'All data elements exclude women attending ANC.',
                'sort_order' => 10,
                'indicators' => [
                    $this->hctAgeServicePointIndicator('hct_negative_male', 'No. of ind. Tested HIV negative (male)', false, 10),
                    $this->hctAgeServicePointIndicator('hct_negative_female', 'No. of ind. Tested HIV negative (female)', false, 20),
                    $this->hctAgeServicePointIndicator('hct_positive_male', 'No. of ind. Tested HIV positive (male)', false, 30),
                    $this->hctAgeServicePointIndicator('hct_positive_female', 'No. of ind. Tested HIV positive (female)', false, 40),
                ],
            ],
            [
                'title' => 'HCT Service Summary',
                'code' => 'HCT_SERVICE_SUMMARY',
                'sort_order' => 20,
                'indicators' => [
                    $this->hctServicePointIndicator('hct_negative_subtotal', 'Subtotal A: No. of ind. Tested HIV negative', false, 10),
                    $this->hctServicePointIndicator('hct_positive_subtotal', 'Subtotal B: No. of ind. HIV positive', false, 20),
                    $this->hctServicePointIndicator('hct_total_tested', 'Total no. of ind. HIV tested (Subtotal A + B)', false, 30),
                    $this->hctServicePointIndicator('hct_counseled_tested_result_male', 'No. of ind. HIV counseled & test & result (male)', false, 40),
                    $this->hctServicePointIndicator('hct_counseled_tested_result_female', 'No. of ind. HIV counseled & test & result (female)', false, 50),
                    $this->hctServicePointIndicator('hct_counseled_tested_result_total', 'Total No. of ind. HIV counseled & test & result', false, 60),
                    $this->hctServicePointIndicator('hct_couples_counseled_tested_result', 'Number of couples HIV counseled & test & result', false, 70),
                    $this->hctServicePointIndicator('hct_couples_sero_discordant', 'Number of couples HIV counseled & test & result that are sero-discordant', false, 80),
                    $this->hctServicePointIndicator('sti_clients_hiv_negative', 'No. of STI clients tested HIV negative', false, 90),
                    $this->hctServicePointIndicator('sti_clients_hiv_positive', 'No. of STI clients tested HIV positive', false, 100),
                    $this->hctServicePointIndicator('tbs_patients_hiv_negative', 'No. of TBS patients tested HIV negative', false, 110),
                    $this->hctServicePointIndicator('tbs_patients_hiv_positive', 'No. of TBS patients tested HIV positive', false, 120),
                    $this->hctServicePointIndicator('hiv_negative_tb_screen_score_0', 'No. of HIV neg. clients clinically screened for TB score 0', false, 130),
                    $this->hctServicePointIndicator('hiv_positive_tb_screen_score_0', 'No. of HIV pos. clients clinically screened for TB score 0', false, 140),
                    $this->hctServicePointIndicator('hiv_negative_tb_screen_score_1_plus', 'No. of HIV neg. clients clinically screened for TB score 1+', false, 150),
                    $this->hctServicePointIndicator('hiv_positive_tb_screen_score_1_plus', 'No. of HIV pos. clients clinically screened for TB score 1+', false, 160),
                ],
            ],
            [
                'title' => 'Monitoring and HCT Training',
                'code' => 'HCT_MONITORING_TRAINING',
                'sort_order' => 30,
                'indicators' => [
                    $this->indicator('hct_government_monitoring_visits', 'No. of external onsite monitoring visit(s) conducted by government official(s) in-charge during reporting period using standard check list', 'integer', false, 10),
                    $this->indicator('hct_ta_consultant_monitoring_visits', 'No. of external on site monitoring visit(s) conducted by TA/Consultants during reporting period using standard check list', 'integer', false, 20),
                    $this->indicator('hct_counselors_newly_trained', 'No. of counselor(s) of facility newly trained on HCT (excluding PMTCT) during reporting period', 'integer', false, 30),
                    $this->indicator('hct_counselors_retrained', 'No. of counselor(s) facility re-trained on HCT (excluding PMTCT) during reporting period', 'integer', false, 40),
                    $this->indicator('hct_trained_counselors_provided_services', 'No. of trained counselor(s) who provided HCT services (excluding PMTCT) at the facility during the reporting period', 'integer', false, 50),
                ],
            ],
        ];
    }

    protected function indicator(string $code, string $label, string $type, bool $required, int $sortOrder): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'value_type' => $type,
            'is_required' => $required,
            'sort_order' => $sortOrder,
            'status' => 'active',
        ];
    }

    protected function sexIndicator(string $code, string $label, bool $required, int $sortOrder, string $type = 'integer'): array
    {
        return $this->dimensionIndicator($code, $label, $type, $required, $sortOrder, 'sex', 'Sex', ['male', 'female']);
    }

    protected function newOldIndicator(string $code, string $label, bool $required, int $sortOrder): array
    {
        return $this->dimensionIndicator($code, $label, 'integer', $required, $sortOrder, 'attendance_status', 'Attendance Status', ['new', 'old']);
    }

    protected function hctServicePointIndicator(string $code, string $label, bool $required, int $sortOrder): array
    {
        return $this->dimensionIndicator($code, $label, 'integer', $required, $sortOrder, 'service_point', 'Service Point', $this->hctServicePoints());
    }

    protected function hctAgeServicePointIndicator(string $code, string $label, bool $required, int $sortOrder): array
    {
        return [
            ...$this->indicator($code, $label, 'integer', $required, $sortOrder),
            'dimensions' => [
                [
                    'dimension_key' => 'age_group',
                    'dimension_label' => 'Age Group',
                    'dimension_values' => ['<2', '2-14', '15-19', '20-24', '25+'],
                    'is_required' => $required,
                    'total_strategy' => 'sum_values',
                    'sort_order' => 10,
                ],
                [
                    'dimension_key' => 'service_point',
                    'dimension_label' => 'Service Point',
                    'dimension_values' => $this->hctServicePoints(),
                    'is_required' => $required,
                    'total_strategy' => 'sum_values',
                    'sort_order' => 20,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function hctServicePoints(): array
    {
        return ['In-patient', 'CT Site', 'TB Site', 'Blood Bank', 'FP Site', 'PCT', 'Mobile'];
    }

    /**
     * @param list<string> $dimensionValues
     */
    protected function dimensionIndicator(
        string $code,
        string $label,
        string $type,
        bool $required,
        int $sortOrder,
        string $dimensionKey,
        string $dimensionLabel,
        array $dimensionValues,
    ): array
    {
        return [
            ...$this->indicator($code, $label, $type, $required, $sortOrder),
            'dimensions' => [
                [
                    'dimension_key' => $dimensionKey,
                    'dimension_label' => $dimensionLabel,
                    'dimension_values' => $dimensionValues,
                    'is_required' => $required,
                    'total_strategy' => 'sum_values',
                    'sort_order' => 10,
                ],
            ],
        ];
    }
}
