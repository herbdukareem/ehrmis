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
            app(ReportTemplateAssignmentService::class)->syncAssignments($template->fresh(), $assignments, $actor);
        } else {
            foreach ($assignments as $assignment) {
                $template->assignments()->updateOrCreate(
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
                    $this->sexIndicator('paediatric_outpatient_attendance', 'Paediatric Outpatient Attendance', false, 30),
                    $this->sexIndicator('dental_attendance_new', 'Dental Attendance New', false, 40),
                    $this->sexIndicator('dental_attendance_old', 'Dental Attendance Old', false, 50),
                    $this->sexIndicator('eye_attendance_new', 'Eye Attendance New', false, 60),
                    $this->sexIndicator('eye_attendance_old', 'Eye Attendance Old', false, 70),
                    $this->indicator('road_traffic_accident_cases', 'Road Traffic Accident Cases', 'integer', false, 80),
                    $this->indicator('xray_cases', 'X-ray Cases', 'integer', false, 90),
                    $this->indicator('scanning_cases', 'Scanning Cases', 'integer', false, 100),
                ],
            ],
            [
                'title' => 'Inpatient Statistics',
                'code' => 'INPATIENT_STATISTICS',
                'sort_order' => 30,
                'indicators' => [
                    $this->indicator('inpatient_admission', 'Inpatient Admission', 'integer', false, 10),
                    $this->indicator('inpatient_discharge', 'Inpatient Discharge', 'integer', false, 20),
                    $this->indicator('inpatient_death', 'Inpatient Death', 'integer', false, 30),
                    $this->indicator('bed_complement', 'Bed Complement', 'integer', false, 40),
                    $this->indicator('patient_days', 'Patient Days', 'integer', false, 50),
                    $this->indicator('bed_occupancy_rate', 'Bed Occupancy Rate', 'percentage', false, 60),
                    $this->indicator('average_length_of_stay', 'Average Length of Stay', 'decimal', false, 70),
                    $this->indicator('turnover_interval', 'Turnover Interval', 'decimal', false, 80),
                    $this->indicator('vacant_bed_days', 'Vacant Bed Days', 'integer', false, 90),
                ],
            ],
            [
                'title' => 'Maternal Statistics',
                'code' => 'MATERNAL_STATISTICS',
                'sort_order' => 40,
                'indicators' => [
                    $this->indicator('maternal_attendance', 'Maternal Attendance', 'integer', false, 10),
                    $this->indicator('maternal_admission', 'Maternal Admission', 'integer', false, 20),
                    $this->indicator('maternal_discharge', 'Maternal Discharge', 'integer', false, 30),
                    $this->indicator('maternal_death', 'Maternal Death', 'integer', false, 40),
                    $this->indicator('antenatal_attendance_new', 'Antenatal Attendance New', 'integer', false, 50),
                    $this->indicator('antenatal_attendance_old', 'Antenatal Attendance Old', 'integer', false, 60),
                ],
            ],
            [
                'title' => 'Total Delivery',
                'code' => 'TOTAL_DELIVERY',
                'sort_order' => 50,
                'indicators' => [
                    $this->indicator('total_deliveries', 'Total Deliveries', 'integer', false, 10),
                    $this->indicator('spontaneous_vaginal_delivery', 'Spontaneous Vaginal Delivery', 'integer', false, 20),
                    $this->indicator('assisted_delivery', 'Assisted Delivery', 'integer', false, 30),
                    $this->indicator('caesarean_section', 'Caesarean Section', 'integer', false, 40),
                    $this->indicator('live_births', 'Live Births', 'integer', false, 50),
                    $this->indicator('still_births', 'Still Births', 'integer', false, 60),
                    $this->indicator('total_hiv_tested', 'Total HIV Tested', 'integer', false, 70),
                    $this->indicator('hiv_positive', 'HIV Positive', 'integer', false, 80),
                    $this->indicator('hiv_negative', 'HIV Negative', 'integer', false, 90),
                    $this->indicator('hiv_counselling', 'HIV Counselling', 'integer', false, 100),
                    $this->indicator('gynaecology_attendance', 'Gynaecology Attendance', 'integer', false, 110),
                ],
            ],
            [
                'title' => 'Medical Outpatient Attendance',
                'code' => 'MEDICAL_OUTPATIENT_ATTENDANCE',
                'sort_order' => 60,
                'indicators' => [
                    $this->sexIndicator('medical_outpatient_new', 'Medical Outpatient New', false, 10),
                    $this->sexIndicator('medical_outpatient_old', 'Medical Outpatient Old', false, 20),
                ],
            ],
            [
                'title' => 'Surgical Outpatient Attendance',
                'code' => 'SURGICAL_OUTPATIENT_ATTENDANCE',
                'sort_order' => 70,
                'indicators' => [
                    $this->sexIndicator('surgical_outpatient_new', 'Surgical Outpatient New', false, 10),
                    $this->sexIndicator('surgical_outpatient_old', 'Surgical Outpatient Old', false, 20),
                ],
            ],
            [
                'title' => 'ENT Attendance',
                'code' => 'ENT_ATTENDANCE',
                'sort_order' => 80,
                'indicators' => [
                    $this->sexIndicator('ent_attendance_new', 'ENT Attendance New', false, 10),
                    $this->sexIndicator('ent_attendance_old', 'ENT Attendance Old', false, 20),
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

    protected function sexIndicator(string $code, string $label, bool $required, int $sortOrder): array
    {
        return [
            ...$this->indicator($code, $label, 'integer', $required, $sortOrder),
            'dimensions' => [
                [
                    'dimension_key' => 'sex',
                    'dimension_label' => 'Sex',
                    'dimension_values' => ['male', 'female'],
                    'is_required' => $required,
                    'total_strategy' => 'sum_values',
                    'sort_order' => 10,
                ],
            ],
        ];
    }
}
