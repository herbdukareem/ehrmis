<?php

namespace Database\Seeders\Data;

final class MohSampleAnnualWorkplan2026Data
{
    public static function payload(): array
    {
        return [
            'seed_key' => 'moh-sample-annual-workplan-2026',
            'mda' => [
                'code' => 'MOH',
                'name' => 'MINISTRY OF HEALTH',
            ],
            'workplan' => [
                'year' => 2026,
                'title' => '2026 Sample Annual Workplan',
                'description' => 'Sample annual workplan for Workplan and Performance module testing. This seeded plan mirrors the 2026 Ministry of Health example and includes authoring, approval, amendment, progress, evidence, verification, and weighted performance scenarios.',
                'classification' => 'SAMPLE / TEST DATA ONLY',
                'document_classification' => 'SAMPLE / TEST DATA ONLY',
                'prepared_by_label' => 'Department of Planning, Research & Statistics (sample)',
                'initial_status' => 'draft',
                'overall_goal' => 'Improve equitable access to quality health services through stronger service delivery, public health preparedness, reliable medicines, better health information and accountable management.',
                'strategic_directions' => [
                    'Primary and maternal health service quality',
                    'Disease surveillance, prevention and emergency preparedness',
                    'Clinical quality assurance and referral coordination',
                    'Essential medicines and supply-chain reliability',
                    'Health information, workforce capability and institutional performance',
                ],
                'assumptions' => [
                    'Synthetic data only for system testing.',
                    'Quarterly targets are cumulative where numeric targets are used.',
                    'A missing target row means the indicator is non-applicable for that period.',
                    'Annual targets are independent and should not be inferred from Q4.',
                    'The sample mixes absolute, increase-from-baseline, decrease-from-baseline, and milestone indicators.',
                    'Objective and activity weights are intended to exercise weighted performance aggregation.',
                ],
                'planning_assumptions' => [
                    'Synthetic data only for system testing.',
                    'Quarterly targets are cumulative where numeric targets are used.',
                    'A missing target row means the indicator is non-applicable for that period.',
                    'Annual targets are independent and should not be inferred from Q4.',
                    'The sample mixes absolute, increase-from-baseline, decrease-from-baseline, and milestone indicators.',
                    'Objective and activity weights are intended to exercise weighted performance aggregation.',
                ],
            ],
            'departments' => [
                ['code' => 'PRS', 'name' => 'Planning, Research & Statistics'],
                ['code' => 'PUBH', 'name' => 'Public Health'],
                ['code' => 'MEDSERV', 'name' => 'Medical Services'],
                ['code' => 'PHARM', 'name' => 'Pharmaceutical Services'],
                ['code' => 'HRH', 'name' => 'Human Resources for Health'],
            ],
            'staff_directory' => [
                ['ref' => 'STF-001', 'name' => 'Dr. Amina Bello', 'role' => 'Director, Planning Research & Statistics', 'department_code' => 'PRS'],
                ['ref' => 'STF-002', 'name' => 'Dr. Ibrahim Musa', 'role' => 'Director, Public Health', 'department_code' => 'PUBH'],
                ['ref' => 'STF-003', 'name' => 'Dr. Hauwa Abdullahi', 'role' => 'Director, Medical Services', 'department_code' => 'MEDSERV'],
                ['ref' => 'STF-004', 'name' => 'Pharm. Yusuf Mohammed', 'role' => 'Director, Pharmaceutical Services', 'department_code' => 'PHARM'],
                ['ref' => 'STF-005', 'name' => 'Mrs. Zainab Garba', 'role' => 'Director, Human Resources for Health', 'department_code' => 'HRH'],
                ['ref' => 'STF-006', 'name' => 'Dr. Maryam Sani', 'role' => 'Maternal & Child Health Coordinator', 'department_code' => 'PUBH'],
                ['ref' => 'STF-007', 'name' => 'Mrs. Fatima Lawal', 'role' => 'State Surveillance Officer', 'department_code' => 'PUBH'],
                ['ref' => 'STF-008', 'name' => 'Mr. Abdulrahman Usman', 'role' => 'HMIS / Data Quality Officer', 'department_code' => 'PRS'],
                ['ref' => 'STF-009', 'name' => 'Pharm. Grace Eze', 'role' => 'Supply Chain Officer', 'department_code' => 'PHARM'],
                ['ref' => 'STF-010', 'name' => 'Dr. Chinedu Okafor', 'role' => 'Clinical Quality Assurance Officer', 'department_code' => 'MEDSERV'],
                ['ref' => 'STF-011', 'name' => 'Mr. Samuel Okon', 'role' => 'Monitoring & Evaluation Officer', 'department_code' => 'PRS'],
                ['ref' => 'STF-012', 'name' => 'Nurse Rukayya Adamu', 'role' => 'Primary Health Services Officer', 'department_code' => 'PUBH'],
            ],
            'objectives' => [
                [
                    'code' => 'OBJ-01',
                    'title' => 'Strengthen primary, maternal and newborn health service quality.',
                    'scope_label' => 'Public Health / Medical Services',
                    'department_code' => 'PUBH',
                    'performance_weight' => 22,
                    'planned_cost' => 72000000,
                    'description' => 'Primary, maternal, and newborn service quality improvement across supported facilities.',
                    'activities' => [
                        [
                            'code' => 'ACT-01.1',
                            'title' => 'Conduct quarterly integrated supportive supervision to priority primary and secondary health facilities.',
                            'department_code' => 'PUBH',
                            'description' => 'Conduct quarterly integrated supportive supervision to priority primary and secondary health facilities.',
                            'expected_output' => 'Quarterly supervision completed; corrective action plans documented and followed up.',
                            'performance_weight' => 40,
                            'responsible_ref' => 'STF-012',
                            'supporting_refs' => ['STF-006', 'STF-011'],
                            'start_date' => '2026-01-05',
                            'end_date' => '2026-12-15',
                            'planned_cost' => 18000000,
                            'funding_source' => 'State Budget / Health Sector Support',
                            'indicators' => [
                                self::indicator('IND-01.1A', 'Number of priority facilities receiving integrated supportive supervision', 'Number of priority facilities receiving integrated supportive supervision', 'absolute', 'increase', 0, 'facilities', 25, ['q1' => 6, 'q2' => 12, 'q3' => 19, 'q4' => 25, 'annual' => 25], 0.5),
                                self::indicator('IND-01.1B', 'Percentage of supervision action points closed within 60 days', 'Percentage of supervision action points closed within 60 days', 'increase_from_baseline', 'increase', 55, '%', 75, ['q1' => 60, 'q2' => 65, 'q3' => 70, 'q4' => 75, 'annual' => 75], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-01.2',
                            'title' => 'Strengthen maternal and newborn emergency readiness through targeted drills, referral protocol refresh and equipment-readiness checks.',
                            'department_code' => 'PUBH',
                            'description' => 'Strengthen maternal and newborn emergency readiness through targeted drills, referral protocol refresh and equipment-readiness checks.',
                            'expected_output' => 'Priority facilities demonstrate improved emergency obstetric/newborn referral readiness.',
                            'performance_weight' => 35,
                            'responsible_ref' => 'STF-006',
                            'supporting_refs' => ['STF-003', 'STF-010'],
                            'start_date' => '2026-02-01',
                            'end_date' => '2026-11-30',
                            'planned_cost' => 24000000,
                            'funding_source' => 'State Budget / Partner Support',
                            'indicators' => [
                                self::indicator('IND-01.2A', 'Number of priority facilities completing maternal/newborn emergency readiness drill', 'Number of priority facilities completing maternal/newborn emergency readiness drill', 'absolute', 'increase', 0, 'facilities', 20, ['q1' => 5, 'q2' => 10, 'q3' => 16, 'q4' => 20, 'annual' => 20], 0.5),
                                self::indicator('IND-01.2B', 'Percentage of assessed facilities meeting minimum emergency-readiness standard', 'Percentage of assessed facilities meeting minimum emergency-readiness standard', 'increase_from_baseline', 'increase', 48, '%', 75, ['q1' => 55, 'q2' => 60, 'q3' => 68, 'q4' => 75, 'annual' => 75], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-01.3',
                            'title' => 'Implement maternal and perinatal death review follow-up across designated reporting facilities.',
                            'department_code' => 'PUBH',
                            'description' => 'Implement maternal and perinatal death review follow-up across designated reporting facilities.',
                            'expected_output' => 'Maternal/perinatal review recommendations tracked to closure.',
                            'performance_weight' => 25,
                            'responsible_ref' => 'STF-006',
                            'supporting_refs' => ['STF-008', 'STF-011'],
                            'start_date' => '2026-01-15',
                            'end_date' => '2026-12-20',
                            'planned_cost' => 30000000,
                            'funding_source' => 'State Budget',
                            'indicators' => [
                                self::indicator('IND-01.3A', 'Percentage of notified maternal deaths reviewed within approved review timeline', 'Percentage of notified maternal deaths reviewed within approved review timeline', 'increase_from_baseline', 'increase', 62, '%', 90, ['q1' => 68, 'q2' => 75, 'q3' => 82, 'q4' => 90, 'annual' => 90], 0.5),
                                self::indicator('IND-01.3B', 'Percentage of agreed review recommendations closed', 'Percentage of agreed review recommendations closed', 'increase_from_baseline', 'increase', 40, '%', 80, ['q1' => 50, 'q2' => 60, 'q3' => 70, 'q4' => 80, 'annual' => 80], 0.5),
                            ],
                        ],
                    ],
                ],
                [
                    'code' => 'OBJ-02',
                    'title' => 'Improve disease surveillance, immunization outreach and outbreak readiness.',
                    'scope_label' => 'Public Health',
                    'department_code' => 'PUBH',
                    'performance_weight' => 20,
                    'planned_cost' => 67000000,
                    'description' => 'Improve surveillance timeliness, outreach coverage, and response readiness.',
                    'activities' => [
                        [
                            'code' => 'ACT-02.1',
                            'title' => 'Strengthen Integrated Disease Surveillance and Response reporting, investigation and rapid response readiness.',
                            'department_code' => 'PUBH',
                            'description' => 'Strengthen Integrated Disease Surveillance and Response reporting, investigation and rapid response readiness.',
                            'expected_output' => 'Timelier surveillance reporting and functional outbreak response arrangements.',
                            'performance_weight' => 50,
                            'responsible_ref' => 'STF-007',
                            'supporting_refs' => ['STF-002', 'STF-008'],
                            'start_date' => '2026-01-05',
                            'end_date' => '2026-12-20',
                            'planned_cost' => 22000000,
                            'funding_source' => 'State Budget / Surveillance Support',
                            'indicators' => [
                                self::indicator('IND-02.1A', 'Percentage of expected surveillance reports submitted on time', 'Percentage of expected surveillance reports submitted on time', 'increase_from_baseline', 'increase', 78, '%', 95, ['q1' => 82, 'q2' => 86, 'q3' => 90, 'q4' => 95, 'annual' => 95], 0.5),
                                self::indicator('IND-02.1B', 'Average days from priority alert notification to completed initial investigation', 'Average days from priority alert notification to completed initial investigation', 'decrease_from_baseline', 'decrease', 4.0, 'days', 2.0, ['q1' => 3.5, 'q2' => 3.0, 'q3' => 2.5, 'q4' => 2.0, 'annual' => 2.0], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-02.2',
                            'title' => 'Conduct targeted immunization outreach and defaulter-recovery activities in underserved settlements.',
                            'department_code' => 'PUBH',
                            'description' => 'Conduct targeted immunization outreach and defaulter-recovery activities in underserved settlements.',
                            'expected_output' => 'Underserved settlements reached and zero/under-immunized children linked to routine services.',
                            'performance_weight' => 50,
                            'responsible_ref' => 'STF-002',
                            'supporting_refs' => ['STF-012', 'STF-011'],
                            'start_date' => '2026-02-01',
                            'end_date' => '2026-12-15',
                            'planned_cost' => 45000000,
                            'funding_source' => 'State Budget / Immunization Partner Support',
                            'indicators' => [
                                self::indicator('IND-02.2A', 'Number of targeted outreach sessions completed', 'Number of targeted outreach sessions completed', 'absolute', 'increase', 0, 'sessions', 210, ['q1' => 45, 'q2' => 95, 'q3' => 150, 'q4' => 210, 'annual' => 210], 0.5),
                                self::indicator('IND-02.2B', 'Percentage of planned underserved settlements reached at least once', 'Percentage of planned underserved settlements reached at least once', 'increase_from_baseline', 'increase', 50, '%', 95, ['q1' => 65, 'q2' => 75, 'q3' => 85, 'q4' => 95, 'annual' => 95], 0.5),
                            ],
                        ],
                    ],
                ],
                [
                    'code' => 'OBJ-03',
                    'title' => 'Improve clinical quality assurance, emergency care and referral coordination.',
                    'scope_label' => 'Medical Services',
                    'department_code' => 'MEDSERV',
                    'performance_weight' => 18,
                    'planned_cost' => 35000000,
                    'description' => 'Clinical quality assurance, emergency care, and referral coordination improvement.',
                    'activities' => [
                        [
                            'code' => 'ACT-03.1',
                            'title' => 'Institutionalize quarterly clinical audit and quality-improvement review in designated hospitals.',
                            'department_code' => 'MEDSERV',
                            'description' => 'Institutionalize quarterly clinical audit and quality-improvement review in designated hospitals.',
                            'expected_output' => 'Clinical audit cycle completed and quality-improvement actions monitored.',
                            'performance_weight' => 55,
                            'responsible_ref' => 'STF-010',
                            'supporting_refs' => ['STF-003', 'STF-011'],
                            'start_date' => '2026-01-10',
                            'end_date' => '2026-12-15',
                            'planned_cost' => 15000000,
                            'funding_source' => 'State Budget',
                            'indicators' => [
                                self::indicator('IND-03.1A', 'Number of designated hospitals completing a documented quarterly clinical audit cycle', 'Number of designated hospitals completing a documented quarterly clinical audit cycle', 'absolute', 'increase', 0, 'hospitals', 16, ['q1' => 4, 'q2' => 8, 'q3' => 12, 'q4' => 16, 'annual' => 16], 0.5),
                                self::indicator('IND-03.1B', 'Percentage of clinical audit corrective actions closed by due date', 'Percentage of clinical audit corrective actions closed by due date', 'increase_from_baseline', 'increase', 45, '%', 85, ['q1' => 55, 'q2' => 65, 'q3' => 75, 'q4' => 85, 'annual' => 85], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-03.2',
                            'title' => 'Strengthen emergency referral coordination and feedback between referring and receiving facilities.',
                            'department_code' => 'MEDSERV',
                            'description' => 'Strengthen emergency referral coordination and feedback between referring and receiving facilities.',
                            'expected_output' => 'Referral pathway updated, focal points established and feedback compliance improved.',
                            'performance_weight' => 45,
                            'responsible_ref' => 'STF-003',
                            'supporting_refs' => ['STF-006', 'STF-010'],
                            'start_date' => '2026-03-01',
                            'end_date' => '2026-12-20',
                            'planned_cost' => 20000000,
                            'funding_source' => 'State Budget / Emergency Care Support',
                            'indicators' => [
                                self::indicator('IND-03.2A', 'Percentage of sampled emergency referrals with documented receiving-facility feedback', 'Percentage of sampled emergency referrals with documented receiving-facility feedback', 'increase_from_baseline', 'increase', 32, '%', 80, ['q2' => 50, 'q3' => 65, 'q4' => 80, 'annual' => 80], 0.5),
                                self::indicator('IND-03.2B', 'State emergency referral coordination protocol approved and disseminated', 'State emergency referral coordination protocol approved and disseminated', 'milestone', 'milestone', 0, 'binary', 1, ['q2' => 1, 'annual' => 1], 0.5),
                            ],
                        ],
                    ],
                ],
                [
                    'code' => 'OBJ-04',
                    'title' => 'Improve availability and accountability of essential medicines and health commodities.',
                    'scope_label' => 'Pharmaceutical Services',
                    'department_code' => 'PHARM',
                    'performance_weight' => 20,
                    'planned_cost' => 77000000,
                    'description' => 'Improve stock visibility, quantification, and distribution reliability for priority commodities.',
                    'activities' => [
                        [
                            'code' => 'ACT-04.1',
                            'title' => 'Conduct quarterly medicines availability and stock-management monitoring in designated facilities.',
                            'department_code' => 'PHARM',
                            'description' => 'Conduct quarterly medicines availability and stock-management monitoring in designated facilities.',
                            'expected_output' => 'Routine stock visibility and corrective action on commodity-management gaps.',
                            'performance_weight' => 45,
                            'responsible_ref' => 'STF-009',
                            'supporting_refs' => ['STF-004', 'STF-011'],
                            'start_date' => '2026-01-05',
                            'end_date' => '2026-12-15',
                            'planned_cost' => 12000000,
                            'funding_source' => 'State Budget',
                            'indicators' => [
                                self::indicator('IND-04.1A', 'Tracer essential-medicine stock-out rate in monitored facilities', 'Tracer essential-medicine stock-out rate in monitored facilities', 'decrease_from_baseline', 'decrease', 18, '%', 8, ['q1' => 15, 'q2' => 12, 'q3' => 10, 'q4' => 8, 'annual' => 8], 0.5),
                                self::indicator('IND-04.1B', 'Percentage of monitored facilities submitting complete stock reports on time', 'Percentage of monitored facilities submitting complete stock reports on time', 'increase_from_baseline', 'increase', 70, '%', 95, ['q1' => 78, 'q2' => 84, 'q3' => 90, 'q4' => 95, 'annual' => 95], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-04.2',
                            'title' => 'Complete annual quantification, procurement planning and distribution monitoring for priority essential medicines.',
                            'department_code' => 'PHARM',
                            'description' => 'Complete annual quantification, procurement planning and distribution monitoring for priority essential medicines.',
                            'expected_output' => 'Approved annual quantification/procurement plan and monitored distribution cycle.',
                            'performance_weight' => 55,
                            'responsible_ref' => 'STF-004',
                            'supporting_refs' => ['STF-009', 'STF-001'],
                            'start_date' => '2026-01-15',
                            'end_date' => '2026-12-20',
                            'planned_cost' => 65000000,
                            'funding_source' => 'State Budget / Essential Medicines Programme',
                            'indicators' => [
                                self::indicator('IND-04.2A', 'Annual medicines quantification and procurement plan approved', 'Annual medicines quantification and procurement plan approved', 'milestone', 'milestone', 0, 'binary', 1, ['q2' => 1, 'annual' => 1], 0.5),
                                self::indicator('IND-04.2B', 'Percentage of planned priority commodity distribution rounds completed', 'Percentage of planned priority commodity distribution rounds completed', 'increase_from_baseline', 'increase', 60, '%', 100, ['q1' => 70, 'q2' => 80, 'q3' => 90, 'q4' => 100, 'annual' => 100], 0.5),
                            ],
                        ],
                    ],
                ],
                [
                    'code' => 'OBJ-05',
                    'title' => 'Strengthen health information, workforce capability and MDA-wide performance management.',
                    'scope_label' => 'MDA-wide / Planning, Research & Statistics',
                    'department_code' => 'PRS',
                    'performance_weight' => 20,
                    'planned_cost' => 36000000,
                    'description' => 'Strengthen data quality, capability building, and managed workplan governance.',
                    'activities' => [
                        [
                            'code' => 'ACT-05.1',
                            'title' => 'Conduct quarterly health information data-quality assessment and feedback across selected reporting units.',
                            'department_code' => 'PRS',
                            'description' => 'Conduct quarterly health information data-quality assessment and feedback across selected reporting units.',
                            'expected_output' => 'Data-quality gaps identified, corrected and monitored through feedback cycles.',
                            'performance_weight' => 45,
                            'responsible_ref' => 'STF-008',
                            'supporting_refs' => ['STF-001', 'STF-011'],
                            'start_date' => '2026-01-10',
                            'end_date' => '2026-12-15',
                            'planned_cost' => 18000000,
                            'funding_source' => 'State Budget / HMIS Support',
                            'indicators' => [
                                self::indicator('IND-05.1A', 'Percentage of selected reporting units achieving at least 90% data completeness', 'Percentage of selected reporting units achieving at least 90% data completeness', 'increase_from_baseline', 'increase', 72, '%', 95, ['q1' => 78, 'q2' => 84, 'q3' => 90, 'q4' => 95, 'annual' => 95], 0.5),
                                self::indicator('IND-05.1B', 'Percentage of identified critical data-quality issues closed within 45 days', 'Percentage of identified critical data-quality issues closed within 45 days', 'increase_from_baseline', 'increase', 50, '%', 90, ['q1' => 60, 'q2' => 70, 'q3' => 80, 'q4' => 90, 'annual' => 90], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-05.2',
                            'title' => 'Deliver targeted management, planning and performance-reporting capacity development for departmental focal officers.',
                            'department_code' => 'PRS',
                            'description' => 'Deliver targeted management, planning and performance-reporting capacity development for departmental focal officers.',
                            'expected_output' => 'Departmental focal officers trained and able to submit evidence-based performance reports.',
                            'performance_weight' => 30,
                            'responsible_ref' => 'STF-001',
                            'supporting_refs' => ['STF-005', 'STF-011'],
                            'start_date' => '2026-02-01',
                            'end_date' => '2026-09-30',
                            'planned_cost' => 10000000,
                            'funding_source' => 'State Budget',
                            'indicators' => [
                                self::indicator('IND-05.2A', 'Number of departmental focal officers completing the approved capacity programme', 'Number of departmental focal officers completing the approved capacity programme', 'absolute', 'increase', 0, 'officers', 45, ['q1' => 15, 'q2' => 30, 'q3' => 45, 'q4' => 45, 'annual' => 45], 0.5),
                                self::indicator('IND-05.2B', 'Percentage of trained focal officers scoring at least 70% in post-training assessment', 'Percentage of trained focal officers scoring at least 70% in post-training assessment', 'increase_from_baseline', 'increase', 0, '%', 85, ['q1' => 75, 'q2' => 80, 'q3' => 85, 'q4' => 85, 'annual' => 85], 0.5),
                            ],
                        ],
                        [
                            'code' => 'ACT-05.3',
                            'title' => 'Coordinate annual MDA Workplan governance, quarterly performance review meetings and approved amendment control.',
                            'department_code' => 'PRS',
                            'description' => 'Coordinate annual MDA Workplan governance, quarterly performance review meetings and approved amendment control.',
                            'expected_output' => 'Approved Workplan, quarterly performance reviews and controlled revision/amendment process maintained.',
                            'performance_weight' => 25,
                            'responsible_ref' => 'STF-001',
                            'supporting_refs' => ['STF-011', 'STF-005'],
                            'start_date' => '2026-01-05',
                            'end_date' => '2026-12-22',
                            'planned_cost' => 8000000,
                            'funding_source' => 'State Budget',
                            'indicators' => [
                                self::indicator('IND-05.3A', 'Number of formal MDA performance review meetings completed with minutes and action tracker', 'Number of formal MDA performance review meetings completed with minutes and action tracker', 'absolute', 'increase', 0, 'meetings', 4, ['q1' => 1, 'q2' => 2, 'q3' => 3, 'q4' => 4, 'annual' => 4], 0.5),
                                self::indicator('IND-05.3B', 'Annual MDA performance report approved', 'Annual MDA performance report approved', 'milestone', 'milestone', 0, 'binary', 1, ['annual' => 1], 0.5),
                            ],
                        ],
                    ],
                ],
            ],
            'progress_scenarios' => [
                'q1' => [
                    self::progress(
                        'ACT-01.1',
                        'verified',
                        ['IND-01.1A' => 7, 'IND-01.1B' => 62],
                        3800000,
                        [
                            self::fileEvidence('Supportive supervision Q1 checklist', 'Q1 integrated supervision checklist and follow-up tracker.'),
                            self::fileEvidence('Supportive supervision Q1 field memo', 'Q1 field memo summarizing action points and immediate actions.'),
                        ],
                        'Q1 supervision exceeded the facility target and closed more action points than planned.'
                    ),
                    self::progress(
                        'ACT-01.2',
                        'verified',
                        ['IND-01.2A' => 5, 'IND-01.2B' => 58],
                        4200000,
                        [
                            self::fileEvidence('Emergency readiness drill attendance', 'Attendance and readiness checklist for the Q1 drill cycle.'),
                        ],
                        'Readiness drills completed on target with modest readiness improvement.'
                    ),
                    self::progress(
                        'ACT-01.3',
                        'submitted',
                        ['IND-01.3A' => 70, 'IND-01.3B' => 52],
                        2000000,
                        [
                            self::fileEvidence('Maternal review tracker', 'Submitted tracker awaiting verification.'),
                        ],
                        'Review follow-up has been submitted but not yet verified.'
                    ),
                    self::progress(
                        'ACT-02.1',
                        'verified',
                        ['IND-02.1A' => 84, 'IND-02.1B' => 3.2],
                        4500000,
                        [
                            self::fileEvidence('Surveillance timeliness summary', 'Quarterly surveillance reporting summary.'),
                            self::fileEvidence('Rapid response readiness log', 'Alert investigation and rapid response readiness log.'),
                        ],
                        'Surveillance timeliness improved and investigation response time reduced.'
                    ),
                    self::progress(
                        'ACT-03.1',
                        'verified',
                        ['IND-03.1A' => 4, 'IND-03.1B' => 60],
                        2600000,
                        [
                            self::fileEvidence('Clinical audit action register', 'Q1 clinical audit evidence pack.'),
                        ],
                        'Clinical audits completed as planned with above-target action closure.'
                    ),
                    self::progress(
                        'ACT-04.1',
                        'verified',
                        ['IND-04.1A' => 14, 'IND-04.1B' => 80],
                        2200000,
                        [],
                        'Medicine availability improved and stock reporting timeliness increased.'
                    ),
                    self::progress(
                        'ACT-04.2',
                        'verified',
                        ['IND-04.2B' => 72],
                        9000000,
                        [
                            self::linkEvidence('Priority commodity distribution dashboard', 'https://example.test/workplans/moh-2026/act-04-2-q1', 'Linked dashboard showing Q1 commodity distribution status.'),
                        ],
                        'Distribution rounds exceeded the Q1 target; the milestone target is not applicable in Q1.'
                    ),
                    self::progress(
                        'ACT-05.1',
                        'returned',
                        ['IND-05.1A' => 80, 'IND-05.1B' => 63],
                        3100000,
                        [
                            self::fileEvidence('DQ assessment notes', 'Returned DQA submission pending correction.'),
                        ],
                        'Initial DQA report was returned for correction after review.',
                        'Clarify the reported issue closure evidence before verification.'
                    ),
                    self::progress(
                        'ACT-05.2',
                        'verified',
                        ['IND-05.2A' => 16, 'IND-05.2B' => 78],
                        2700000,
                        [
                            self::fileEvidence('Capacity development attendance', 'Training attendance and post-test summary.'),
                        ],
                        'Capacity programme exceeded the trainee count target with acceptable post-test performance.'
                    ),
                    self::progress(
                        'ACT-05.3',
                        'verified',
                        ['IND-05.3A' => 1],
                        1500000,
                        [
                            self::fileEvidence('Q1 performance review minutes', 'Minutes and action tracker for the first quarterly review meeting.'),
                        ],
                        'One formal review meeting completed; annual milestone remains non-applicable in Q1.'
                    ),
                ],
            ],
            'amendment_revision' => [
                'reason' => 'Expanded underserved-settlement coverage and tighter stock-out expectations require a formal amendment revision.',
                'activity_updates' => [
                    'ACT-02.2' => [
                        'planned_cost' => 52000000,
                        'supporting_refs' => ['STF-012', 'STF-011', 'STF-005'],
                        'indicator_target_updates' => [
                            'IND-02.2A' => ['q4' => 240],
                        ],
                    ],
                    'ACT-04.1' => [
                        'indicator_target_updates' => [
                            'IND-04.1A' => ['q4' => 7],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function indicator(
        string $code,
        string $label,
        string $description,
        string $mode,
        string $direction,
        int|float|null $baseline,
        ?string $unit,
        int|float|null $annualTarget,
        array $targets,
        float $weight
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'description' => $description,
            'mode' => $mode,
            'direction' => $direction,
            'baseline' => $baseline,
            'unit' => $unit,
            'annual_target' => $annualTarget,
            'targets' => $targets,
            'weight' => $weight,
        ];
    }

    private static function progress(
        string $activityCode,
        string $status,
        array $actuals,
        int|float|null $reportedExpenditure,
        array $evidence,
        string $summary,
        ?string $returnReason = null
    ): array {
        return [
            'activity_code' => $activityCode,
            'status' => $status,
            'actuals' => $actuals,
            'reported_expenditure' => $reportedExpenditure,
            'evidence' => $evidence,
            'achievement_summary' => $summary,
            'return_reason' => $returnReason,
        ];
    }

    private static function fileEvidence(string $title, string $notes): array
    {
        return [
            'type' => 'file',
            'title' => $title,
            'notes' => $notes,
            'content' => $title."\n\n".$notes."\n\nSeeded sample evidence for the 2026 MOH workplan.",
        ];
    }

    private static function linkEvidence(string $title, string $url, string $notes): array
    {
        return [
            'type' => 'link',
            'title' => $title,
            'url' => $url,
            'notes' => $notes,
        ];
    }
}
