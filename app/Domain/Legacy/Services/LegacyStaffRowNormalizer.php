<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Legacy\Support\LegacyIdentifier;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Services\PromotionPolicyService;
use App\Domain\Staff\Services\RetirementPolicyService;
use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyStaffRowNormalizer
{
    protected array $mdaCache = [];

    protected array $departmentCache = [];

    protected array $departmentsByMdaCache = [];

    protected array $stationsByMdaCache = [];

    protected array $salaryScaleCache = [];

    protected array $cadreCache = [];

    protected array $rankCache = [];

    protected array $qualificationTypeCache = [];

    public function __construct(
        protected LegacyDateParser $dateParser,
        protected PromotionPolicyService $promotionPolicyService,
        protected RetirementPolicyService $retirementPolicyService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(array $legacyRow, string $sourceTable, ?array $masterRow = null, bool $allowCreate = false): array
    {
        $issues = [];
        $mda = $this->resolveMda($legacyRow['mda'] ?? null, $masterRow['mda'] ?? null);
        $salaryScale = $this->resolveSalaryScale(
            $legacyRow['salary_scale'] ?? null,
            $masterRow['salary_scale_code'] ?? ($masterRow['salary_scale'] ?? null),
            $mda?->id,
        );
        $fullName = $this->buildFullName($legacyRow, $masterRow);
        [$surname, $firstName, $middleName] = $this->splitNameParts($legacyRow, $masterRow, $fullName);

        $dateOfBirth = $this->parseDate($legacyRow['dob'] ?? ($legacyRow['date_of_birth'] ?? null), 'date_of_birth', $issues);
        $dateOfFirstAppointment = $this->parseDate($legacyRow['dfa'] ?? ($legacyRow['date_of_first_appointment'] ?? ($masterRow['date_of_first_appointment'] ?? null)), 'date_of_first_appointment', $issues);
        $dateOfLastPromotion = $this->parseDate($legacyRow['dpa'] ?? ($legacyRow['date_of_last_promotion'] ?? ($masterRow['date_of_last_promotion'] ?? null)), 'date_of_last_promotion', $issues);
        $legacyEdor = $this->parseDate($legacyRow['edor'] ?? ($legacyRow['date_of_retirement_by_age'] ?? ($masterRow['date_of_retirement_by_age'] ?? null)), 'expected_retirement_date', $issues);
        $legacyNextPromotionDate = $this->parseDate($legacyRow['next_promotion_date'] ?? null, 'next_promotion_date', $issues);

        $computedEdor = $this->retirementPolicyService
            ->calculateExpectedRetirementDate(
                $dateOfBirth ? Carbon::parse($dateOfBirth) : null,
                $dateOfFirstAppointment ? Carbon::parse($dateOfFirstAppointment) : null,
            )?->toDateString();

        $level = $this->toInteger($legacyRow['level'] ?? ($masterRow['level'] ?? null));
        $step = $this->toInteger($legacyRow['step'] ?? ($masterRow['step'] ?? null));

        $computedNextPromotionDate = null;

        if ($dateOfLastPromotion && $salaryScale && $level !== null) {
            $computedNextPromotionDate = $this->promotionPolicyService
                ->calculateNextPromotionDate(Carbon::parse($dateOfLastPromotion), $salaryScale->code, $level)?->toDateString();
        }

        if ($legacyEdor && $computedEdor && $legacyEdor !== $computedEdor) {
            $issues[] = $this->warning('expected_retirement_date', 'edor_mismatch', 'Imported EDOR `'.$legacyEdor.'` differs from computed EDOR `'.$computedEdor.'`.');
        }

        if ($legacyNextPromotionDate && $computedNextPromotionDate && $legacyNextPromotionDate !== $computedNextPromotionDate) {
            $issues[] = $this->warning('next_promotion_date', 'next_promotion_mismatch', 'Imported next promotion date `'.$legacyNextPromotionDate.'` differs from computed date `'.$computedNextPromotionDate.'`.');
        }

        $departmentName = $legacyRow['department'] ?? ($masterRow['department'] ?? null);
        $departmentCode = $legacyRow['department_code'] ?? ($masterRow['department_code'] ?? null);
        $department = $this->resolveDepartment($mda?->id, $departmentName, $departmentCode);
        $station = $this->resolveStation($mda?->id, $legacyRow['station'] ?? ($masterRow['station'] ?? null), $mda);
        $cadreName = $legacyRow['cadre'] ?? ($legacyRow['initial_cadre'] ?? ($masterRow['cadre'] ?? null));
        $cadre = $this->resolveCadre($cadreName, $salaryScale?->id, $department?->id);

        $rankName = $legacyRow['rank'] ?? ($legacyRow['initial_rank'] ?? ($masterRow['rank'] ?? null));
        $rank = $this->resolveRank($rankName, $cadre?->id, $level, $salaryScale?->id);

        if ($rank && (! $cadre || $rank->cadre_id !== $cadre->id)) {
            $cadre = $rank->cadre;
        }

        if (! $cadre && $allowCreate) {
            $cadre = $this->createCadre($cadreName, $salaryScale?->id, $department?->id, $issues);
        }

        if (! $rank && $allowCreate && $cadre) {
            $rank = $this->createRank($rankName, $cadre, $level, $issues);
        }
        $qualificationName = $this->cleanString($legacyRow['qualification'] ?? ($masterRow['qualifications'] ?? null));
        $highestQualificationName = $this->cleanString($legacyRow['highest_qualification'] ?? ($masterRow['highest_qualification'] ?? null));
        $qualificationType = $this->resolveQualificationType($highestQualificationName ?? $qualificationName);
        $allowances = $this->normalizeAllowances($legacyRow, $masterRow, $issues);
        $isRetiredFromSource = $this->truthy($legacyRow['is_retired'] ?? ($masterRow['is_retired'] ?? null));
        $isDuplicate = $this->truthy($legacyRow['duplicate'] ?? null);
        $resolvedExpectedRetirementDate = $this->resolveExpectedRetirementDate(
            $legacyEdor,
            $computedEdor,
            $dateOfFirstAppointment,
            $dateOfLastPromotion,
            $isRetiredFromSource,
        );
        $isRetired = $isRetiredFromSource || $this->isRetiredByExpectedRetirementDate($resolvedExpectedRetirementDate);

        if (! $isRetiredFromSource && $isRetired) {
            $issues[] = $this->warning(
                'is_retired',
                'retirement_status_inferred',
                'Staff was marked retired because the resolved EDOR `'.$resolvedExpectedRetirementDate.'` is due.',
            );
        }
        $legacyCno = LegacyIdentifier::normalize($legacyRow['cno'] ?? ($masterRow['cno'] ?? null));
        $legacyPsn = LegacyIdentifier::normalize($legacyRow['psn'] ?? ($masterRow['psn'] ?? null));
        $legacyCnoPsn = LegacyIdentifier::normalize($legacyRow['cno_psn'] ?? $this->makeLegacyCnoPsn($legacyCno, $legacyPsn));
        $staffNumber = $legacyCno ?? 'new-' . uniqid();

        if ($legacyCno === null && $legacyPsn === null && $staffNumber !== null) {
            $staffNumber = $this->makeProvisionalStaffNumber(
                $legacyRow,
                $sourceTable,
                $mda?->code,
                $fullName,
                $dateOfBirth,
            ) ?? $staffNumber;
            $issues[] = $this->warning(
                'staff_number',
                'provisional_identifier',
                'A provisional staff number was generated because the source row has no CNO or PSN. Verify it during review.',
            );
        }

        return [
            'source_table' => $sourceTable,
            'legacy_staff_id' => $sourceTable === 'staff_list' ? ($legacyRow['id'] ?? null) : null,
            'legacy_master_staff_id' => $masterRow['id'] ?? ($sourceTable === 'master_staff_list' ? ($legacyRow['id'] ?? null) : null),
            'staff_number' => $staffNumber,
            'legacy_cno' => $legacyCno,
            'legacy_psn' => $legacyPsn,
            'legacy_cno_psn' => $legacyCnoPsn,
            'mda_id' => $mda?->id,
            'mda_name' => $mda?->name,
            'department_id' => $department?->id,
            'department_name' => $department?->name ?? $this->cleanString($departmentName ?? $departmentCode),
            'station_id' => $station?->id,
            'station_name' => $station?->name ?? $this->cleanString($legacyRow['station'] ?? ($masterRow['station'] ?? null)),
            'location_name' => $this->cleanString($legacyRow['location'] ?? ($masterRow['location'] ?? null)),
            'salary_scale_id' => $salaryScale?->id,
            'salary_scale_code' => $salaryScale?->code ?? $this->cleanCode($legacyRow['salary_scale'] ?? ($masterRow['salary_scale_code'] ?? null)),
            'level' => $level,
            'step' => $step,
            'basic_salary' => $this->toDecimal($legacyRow['basic_salary'] ?? null),
            'gross_salary' => $this->toDecimal($legacyRow['gross'] ?? null),
            'cadre_id' => $cadre?->id,
            'cadre_name' => $cadre?->name ?? $this->cleanString($legacyRow['cadre'] ?? ($legacyRow['initial_cadre'] ?? ($masterRow['cadre'] ?? null))),
            'rank_id' => $rank?->id,
            'rank_name' => $rank?->name ?? $this->cleanString($legacyRow['rank'] ?? ($legacyRow['initial_rank'] ?? ($masterRow['rank'] ?? null))),
            'staff_category' => $this->cleanString($legacyRow['staff_category'] ?? null),
            'initial_rank' => $this->cleanString($legacyRow['initial_rank'] ?? null),
            'qualification_type_id' => $qualificationType?->id,
            'qualification_code' => $qualificationType?->code,
            'qualification_name' => $qualificationName,
            'highest_qualification_name' => $highestQualificationName,
            'specialization' => $this->cleanString($legacyRow['specialization'] ?? ($masterRow['area_of_specialization'] ?? null)),
            'full_name' => $fullName,
            'surname' => $surname,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'sex' => $this->normalizeSex($legacyRow['sex'] ?? ($masterRow['sex'] ?? null)),
            'date_of_birth' => $dateOfBirth,
            'date_first_appointment' => $dateOfFirstAppointment,
            'date_last_promotion' => $dateOfLastPromotion,
            'legacy_expected_retirement_date' => $legacyEdor,
            'computed_expected_retirement_date' => $computedEdor,
            'resolved_expected_retirement_date' => $resolvedExpectedRetirementDate,
            'next_promotion_date' => $legacyNextPromotionDate,
            'computed_next_promotion_date' => $computedNextPromotionDate,
            'lga' => $this->cleanString($legacyRow['lga'] ?? ($masterRow['lga'] ?? null)),
            'state_of_origin' => $this->cleanString($masterRow['state'] ?? null),
            'phone' => $this->cleanString($masterRow['phone_number'] ?? null),
            'email' => null,
            'address' => null,
            'marital_status' => null,
            'file_no' => $this->cleanString($legacyRow['file_no'] ?? ($masterRow['hmd_file_number'] ?? null)),
            'allowances' => $allowances,
            'is_retired' => $isRetired,
            'is_duplicate' => $isDuplicate,
            'employment_status' => $isRetired ? 'retired' : 'active',
            'status' => $isRetired ? 'retired' : ($isDuplicate ? 'duplicate' : 'active'),
            'dedupe_key' => $staffNumber ?? Str::upper(Str::slug($fullName.'-'.$dateOfBirth, '_')),
            'issues' => $issues,
            'master_match_confidence' => $masterRow ? 'confident' : 'none',
        ];
    }

    public function findMasterRow(array $legacyRow): ?array
    {
        $cno = LegacyIdentifier::normalize($legacyRow['cno'] ?? null);
        $psn = LegacyIdentifier::normalize($legacyRow['psn'] ?? null);
        $name = $this->cleanString($legacyRow['name'] ?? null);
        $sink = [];
        $dob = $this->parseDate($legacyRow['dob'] ?? ($legacyRow['date_of_birth'] ?? null), 'date_of_birth', $sink);

        $query = DB::connection('legacy')->table('master_staff_list');

        if ($cno && $psn) {
            $row = (clone $query)->where('cno', $cno)->where('psn', $psn)->first();

            if ($row) {
                return (array) $row;
            }
        }

        if ($cno) {
            $rows = (clone $query)->where('cno', $cno)->limit(2)->get();

            if ($rows->count() === 1) {
                return (array) $rows->first();
            }
        }

        if ($psn) {
            $rows = (clone $query)->where('psn', $psn)->limit(2)->get();

            if ($rows->count() === 1) {
                return (array) $rows->first();
            }
        }

        if ($name && $dob) {
            $rows = (clone $query)
                ->where('date_of_birth', $dob)
                ->limit(2)
                ->get();

            $matched = $rows->first(function ($row) use ($name) {
                $candidateName = strtolower(trim(implode(' ', array_filter([
                    $row->surname ?? null,
                    $row->first_name ?? null,
                    $row->other_name ?? null,
                ]))));

                return $candidateName === strtolower($name);
            });

            if ($matched) {
                return (array) $matched;
            }
        }

        return null;
    }

    protected function resolveMda(?string $primary, ?string $secondary): ?Mda
    {
        $cacheKey = strtolower(trim(($primary ?? '').'|'.($secondary ?? '')));

        if (array_key_exists($cacheKey, $this->mdaCache)) {
            return $this->mdaCache[$cacheKey];
        }

        foreach ([$primary, $secondary] as $candidate) {
            $clean = $this->cleanString($candidate);

            if ($clean === null) {
                continue;
            }

            $mda = Mda::query()
                ->where('code', $this->cleanCode($clean))
                ->orWhereRaw('LOWER(name) = ?', [strtolower($clean)])
                ->first();

            if ($mda) {
                return $this->mdaCache[$cacheKey] = $mda;
            }
        }

        return $this->mdaCache[$cacheKey] = null;
    }

    protected function resolveDepartment(?int $mdaId, ?string $departmentName, ?string $departmentCode = null): ?Department
    {
        $name = $this->cleanString($departmentName);
        $code = $this->cleanCode($departmentCode);

        if (! $mdaId || ($name === null && $code === null)) {
            return null;
        }

        $cacheKey = $mdaId.'|'.strtolower($name ?? '').'|'.($code ?? '');

        if (array_key_exists($cacheKey, $this->departmentCache)) {
            return $this->departmentCache[$cacheKey];
        }

        $departments = $this->departmentsByMdaCache[$mdaId] ??= Department::query()
            ->forMda($mdaId)
            ->get();

        if ($code !== null) {
            $matchedByCode = $departments->first(
                fn (Department $department): bool => $this->cleanCode($department->code) === $code
            );

            if ($matchedByCode) {
                return $this->departmentCache[$cacheKey] = $matchedByCode;
            }
        }

        if ($name === null) {
            return $this->departmentCache[$cacheKey] = null;
        }

        return $this->departmentCache[$cacheKey] = $departments->first(
            fn (Department $department): bool => strtolower((string) $department->name) === strtolower($name)
        );
    }

    protected function resolveStation(?int $mdaId, ?string $stationName, ?Mda $mda = null): ?Station
    {
        $name = $this->cleanString($stationName);

        if (! $mdaId || $name === null) {
            return null;
        }

        $stations = $this->stationsByMdaCache[$mdaId] ??= Station::query()
            ->forMda($mdaId)
            ->get();
        $exactMatch = $stations->first(
            fn (Station $station): bool => strtolower($station->name) === strtolower($name)
        );

        if ($exactMatch) {
            return $exactMatch;
        }

        $candidateKeys = $this->buildStationMatchKeys($name);

        if ($candidateKeys === []) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($stations as $station) {
            $score = $this->scoreStationMatch($candidateKeys, $station->name);

            if ($score > $bestScore) {
                $bestMatch = $station;
                $bestScore = $score;
            }
        }

        if ($bestMatch) {
            return $bestMatch;
        }

        if ($this->isHeadquartersAlias($name)) {
            return $this->headquartersStation($stations);
        }

        if ($mda && $this->cleanCode($name) === $this->cleanCode($mda->code)) {
            return $this->headquartersStation($stations);
        }

        return null;
    }

    protected function resolveSalaryScale(?string $primary, ?string $secondary, ?int $mdaId = null): ?SalaryScale
    {
        $cacheKey = strtolower(trim(($primary ?? '').'|'.($secondary ?? ''))).'|'.($mdaId ?? 'global');

        if (array_key_exists($cacheKey, $this->salaryScaleCache)) {
            return $this->salaryScaleCache[$cacheKey];
        }

        foreach ([$secondary, $primary] as $candidate) {
            $code = $this->cleanCode($candidate);

            if ($code === null) {
                continue;
            }

            if ($code === 'CONHESS') {
                $code = 'CH';
            }

            if ($code === 'CONMESS') {
                $code = 'CM';
            }

            if ($code === 'GRADELEVEL') {
                $code = 'GL';
            }

            if ($code === 'SPECIALGRADE') {
                $code = 'SG';
            }

            $salaryScale = SalaryScale::query()
                ->where('code', $code)
                ->first();

            if ($salaryScale) {
                return $this->salaryScaleCache[$cacheKey] = $salaryScale;
            }
        }

        return $this->salaryScaleCache[$cacheKey] = null;
    }

    protected function resolveCadre(?string $cadreName, ?int $salaryScaleId, ?int $departmentId = null): ?Cadre
    {
        $name = $this->cleanString($cadreName);

        if ($name === null) {
            return null;
        }

        $cacheKey = strtolower($name).'|'.($salaryScaleId ?? '').'|'.($departmentId ?? '');

        if (array_key_exists($cacheKey, $this->cadreCache)) {
            return $this->cadreCache[$cacheKey];
        }

        $query = Cadre::query()->whereRaw('LOWER(name) = ?', [strtolower($name)]);

        if ($salaryScaleId) {
            $query->where('salary_scale_id', $salaryScaleId);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        return $this->cadreCache[$cacheKey] = $query->first();
    }

    protected function resolveRank(?string $rankName, ?int $cadreId, ?int $level, ?int $salaryScaleId = null): ?Rank
    {
        $name = $this->cleanString($rankName);

        if ($name === null) {
            return null;
        }

        $cacheKey = strtolower($name).'|'.($cadreId ?? '').'|'.($level ?? '').'|'.($salaryScaleId ?? '');

        if (array_key_exists($cacheKey, $this->rankCache)) {
            return $this->rankCache[$cacheKey];
        }

        $query = Rank::query()->with('cadre')->whereRaw('LOWER(name) = ?', [strtolower($name)]);

        if ($cadreId) {
            $query->where('cadre_id', $cadreId);
        }

        if ($level !== null) {
            $query->where('level', $level);
        }

        $rank = $query->first();

        if ($rank) {
            return $this->rankCache[$cacheKey] = $rank;
        }

        if ($cadreId) {
            $contextRankQuery = Rank::query()->with('cadre');
            $contextRankQuery->where('cadre_id', $cadreId);

            if ($level !== null) {
                $contextRankQuery->where('level', $level);
            }

            if ($salaryScaleId !== null) {
                $contextRankQuery->where('salary_scale_id', $salaryScaleId);
            }

            $contextRanks = $contextRankQuery->limit(2)->get();

            if ($contextRanks->count() === 1) {
                return $this->rankCache[$cacheKey] = $contextRanks->first();
            }
        }

        $fallbackQuery = Rank::query()->with('cadre')->whereRaw('LOWER(name) = ?', [strtolower($name)]);

        if ($level !== null) {
            $fallbackQuery->where('level', $level);
        }

        if ($salaryScaleId !== null) {
            $fallbackQuery->where('salary_scale_id', $salaryScaleId);
        }

        $fallbackRanks = $fallbackQuery->limit(2)->get();

        return $this->rankCache[$cacheKey] = ($fallbackRanks->count() === 1 ? $fallbackRanks->first() : null);
    }

    protected function createCadre(?string $cadreName, ?int $salaryScaleId, ?int $departmentId, array &$issues): ?Cadre
    {
        $name = $this->cleanString($cadreName);

        if ($name === null || ! $salaryScaleId) {
            return null;
        }

        $cadre = Cadre::query()->create([
            'salary_scale_id' => $salaryScaleId,
            'department_id' => $departmentId,
            'name' => $name,
            'status' => 'active',
        ]);

        $this->cadreCache[strtolower($name).'|'.$salaryScaleId.'|'.($departmentId ?? '')] = $cadre;

        $issues[] = $this->warning(
            'cadre',
            'cadre_auto_created',
            'Cadre `'.$name.'` did not exist and was created automatically during import. Verify its department and salary scale.',
        );

        return $cadre;
    }

    protected function createRank(?string $rankName, Cadre $cadre, ?int $level, array &$issues): ?Rank
    {
        $name = $this->cleanString($rankName);

        if ($name === null) {
            return null;
        }

        $rank = Rank::query()->create([
            'cadre_id' => $cadre->id,
            'salary_scale_id' => $cadre->salary_scale_id,
            'name' => $name,
            'level' => $level,
            'status' => 'active',
        ]);

        $rank->setRelation('cadre', $cadre);

        $this->rankCache[strtolower($name).'|'.$cadre->id.'|'.($level ?? '').'|'.($cadre->salary_scale_id ?? '')] = $rank;

        $issues[] = $this->warning(
            'rank',
            'rank_auto_created',
            'Rank `'.$name.'` did not exist under cadre `'.$cadre->name.'` and was created automatically during import. Verify its level and salary scale.',
        );

        return $rank;
    }

    protected function resolveQualificationType(?string $qualificationName): ?QualificationType
    {
        $name = $this->cleanString($qualificationName);

        if ($name === null) {
            return null;
        }

        $cacheKey = strtolower($name);

        if (array_key_exists($cacheKey, $this->qualificationTypeCache)) {
            return $this->qualificationTypeCache[$cacheKey];
        }

        $code = UnifiedQualificationCatalog::canonicalCodeFor($name) ?? Str::upper(Str::slug($name, '_'));

        return $this->qualificationTypeCache[$cacheKey] = QualificationType::query()
            ->where(function ($query) use ($code, $name): void {
                $query
                    ->where('code', $code)
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($name)]);
            })
            ->first();
    }

    protected function headquartersStation($stations): ?Station
    {
        return $stations
            ->filter(function (Station $station): bool {
                $name = strtolower($station->name);

                return str_contains($name, 'hq') || str_contains($name, 'headquarters');
            })
            ->sortBy('name')
            ->first();
    }

    protected function buildFullName(array $legacyRow, ?array $masterRow): string
    {
        $name = $this->cleanString($legacyRow['name'] ?? null);

        if ($name !== null) {
            return $name;
        }

        $parts = array_filter([
            $this->cleanString($masterRow['surname'] ?? null),
            $this->cleanString($masterRow['first_name'] ?? null),
            $this->cleanString($masterRow['other_name'] ?? null),
        ]);

        return $parts !== [] ? implode(' ', $parts) : 'Legacy Staff '.($legacyRow['id'] ?? 'unknown');
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    protected function splitNameParts(array $legacyRow, ?array $masterRow, string $fullName): array
    {
        $surname = $this->cleanString($masterRow['surname'] ?? null);
        $firstName = $this->cleanString($masterRow['first_name'] ?? null);
        $middleName = $this->cleanString($masterRow['other_name'] ?? null);

        if ($surname && $firstName) {
            return [$surname, $firstName, $middleName];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $surname = $surname ?? ($parts[0] ?? 'UNKNOWN');
        $firstName = $firstName ?? ($parts[1] ?? $surname);
        $middleName = $middleName ?? (count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null);

        return [$surname, $firstName, $middleName];
    }

    protected function normalizeSex(?string $value): ?string
    {
        $clean = Str::upper($this->cleanString($value) ?? '');

        return match ($clean) {
            'M', 'MALE' => 'male',
            'F', 'FEMALE' => 'female',
            default => null,
        };
    }

    /**
     * @return array<string, array{is_eligible: bool}>
     */
    protected function normalizeAllowances(array $legacyRow, ?array $masterRow, array &$issues): array
    {
        $map = [
            'shift' => [
                'eligible' => [$legacyRow['shift'] ?? null, $legacyRow['shift_'] ?? null, $legacyRow['shift_initial'] ?? null, $legacyRow['shift_value'] ?? null, $masterRow['shift'] ?? null],
            ],
            'hazard' => [
                'eligible' => [$legacyRow['hazard'] ?? null, $legacyRow['hazard_'] ?? null, $legacyRow['hazard_initial'] ?? null, $legacyRow['hazard_value'] ?? null, $masterRow['hazard'] ?? null],
            ],
            'teaching' => [
                'eligible' => [$legacyRow['teaching'] ?? null, $legacyRow['teaching_'] ?? null, $legacyRow['teaching_initial'] ?? null, $legacyRow['teaching_value'] ?? null, $legacyRow['actual_teaching_allowance'] ?? null, $masterRow['teaching'] ?? null, $masterRow['actual_teaching_allowance'] ?? null],
            ],
            'specialty' => [
                'eligible' => [$legacyRow['specialist'] ?? null, $legacyRow['specialist_'] ?? null, $legacyRow['specialist_initial'] ?? null, $legacyRow['specialist_value'] ?? null, $legacyRow['sepecialist'] ?? null, $masterRow['sepecialist'] ?? null],
            ],
            'rural' => [
                'eligible' => [$legacyRow['rural'] ?? null, $legacyRow['rural_'] ?? null, $legacyRow['rural_initial'] ?? null, $legacyRow['rural_value'] ?? null, $legacyRow['rural_allowance'] ?? null, $masterRow['rural_allowance'] ?? null],
            ],
            'domestic' => [
                'eligible' => [$legacyRow['domestic'] ?? null, $legacyRow['domestic_'] ?? null, $legacyRow['domestic_allowance'] ?? null, $masterRow['domestic_allowance'] ?? null],
            ],
        ];

        $allowances = [];
        $callAllowanceCode = $this->resolveCallAllowanceTypeCode($legacyRow, $masterRow);

        foreach ($map as $code => $config) {
            $eligible = false;

            foreach ($config['eligible'] as $candidate) {
                if ($this->truthy($candidate)) {
                    $eligible = true;
                    break;
                }
            }

            $allowances[$code] = [
                'is_eligible' => $eligible,
            ];
        }

        if ($callAllowanceCode !== null) {
            $allowances[$callAllowanceCode] = [
                'is_eligible' => true,
            ];
        } elseif ($this->hasLegacyCallAllowanceSignal($legacyRow, $masterRow)) {
            $issues[] = $this->warning(
                'call_allowance',
                'call_allowance_unresolved',
                'Legacy call allowance eligibility was detected, but no canonical call allowance type could be resolved from the available cadre, specialization, salary scale, and level data.'
            );
        }

        return $allowances;
    }

    /**
     * @return array{allowances: array<string, array{is_eligible: bool}>, issues: array<int, array<string, mixed>>}
     */
    public function normalizeAllowanceEligibility(array $legacyRow, ?array $masterRow = null): array
    {
        $issues = [];

        return [
            'allowances' => $this->normalizeAllowances($legacyRow, $masterRow, $issues),
            'issues' => $issues,
        ];
    }

    protected function resolveCallAllowanceTypeCode(array $legacyRow, ?array $masterRow): ?string
    {
        $explicitCallMap = [
            'CALLDOC' => 'call_doctor',
            'CALLPHARMLAB' => 'call_pharm_lab',
            'CALLOPTODD' => 'call_opt_odd',
            'CALLNURSEOTHERS' => 'call_nurse_others',
        ];

        foreach ([
            $legacyRow['call'] ?? null,
            $legacyRow['call_'] ?? null,
            $legacyRow['call_initial'] ?? null,
            $legacyRow['call_allowance'] ?? null,
            $legacyRow['actual_call_allowance'] ?? null,
            $masterRow['actual_call_allowance'] ?? null,
        ] as $candidate) {
            $normalized = $this->cleanCode($candidate);

            if ($normalized !== null && isset($explicitCallMap[$normalized])) {
                return $explicitCallMap[$normalized];
            }
        }

        if (! $this->hasLegacyCallAllowanceSignal($legacyRow, $masterRow)) {
            return null;
        }

        $cadre = Str::upper($this->cleanString(
            $legacyRow['cadre'] ?? ($legacyRow['initial_cadre'] ?? ($masterRow['cadre'] ?? ($masterRow['actual_cadre'] ?? null)))
        ) ?? '');
        $rank = Str::upper($this->cleanString(
            $legacyRow['rank'] ?? ($legacyRow['initial_rank'] ?? ($masterRow['rank'] ?? null))
        ) ?? '');
        $specialization = Str::upper($this->cleanString(
            $legacyRow['specialization'] ?? ($masterRow['area_of_specialization'] ?? null)
        ) ?? '');
        $salaryScaleCode = $this->cleanCode($legacyRow['salary_scale'] ?? ($masterRow['salary_scale_code'] ?? ($masterRow['salary_scale'] ?? null)));
        $level = $this->toInteger($legacyRow['level'] ?? ($masterRow['level'] ?? null));
        $cadreKey = $this->cleanCode($cadre) ?? '';
        $rankKey = $this->cleanCode($rank) ?? '';

        if (in_array($cadreKey, ['MEDICALOFFICER', 'REGISTRARMO', 'REGISTRARDENTAL', 'DENTALOFFICER'], true)) {
            return 'call_doctor';
        }

        if ($cadreKey === 'OPTOMETRIST') {
            return 'call_opt_odd';
        }

        if (
            $cadreKey === 'NURSINGOFFICER'
            && $level !== null
            && $level >= 5
            && in_array($specialization, [
                'ENT',
                'ICN',
                'IC NURSE',
                'PSYCHIATRIC',
                'OPTHALMIC',
                'ORTHOPEDIC',
                'PAEDIATRIC',
                'DIALYSIS NURSE',
                'DN',
                'PON',
                'ANAESTHESIA',
                'ANAESTESIA',
                'ECG',
                'A&E',
            ], true)
        ) {
            return 'call_nurse_others';
        }

        if (
            $salaryScaleCode === 'CH'
            && $level !== null
            && $level >= 5
            && (
                str_contains($cadreKey, 'NURS')
                || str_contains($rankKey, 'NURS')
                || in_array($rankKey, ['SN', 'SNO', 'NO', 'CNO', 'PNO'], true)
            )
        ) {
            return 'call_nurse_others';
        }

        if (
            $level !== null
            && $level >= 7
            && in_array($cadreKey, [
                'SCIENTIFICOFFICER',
                'MEDICALLABSCIENTIST',
                'MEDLABSCIENTIST',
                'MEDLABTECHNICIAN',
                'PHARMTECHNICIAN',
                'RADIOGRAPHER',
                'PHYSIOTHERAPIST',
                'PHARMACIST',
                'SCILABTECHNOLOGIST',
                'TECHNICALOFFICERBIOMED',
                'BIOMEDICALENGINEER',
                'CONSULTANTPHARM',
            ], true)
        ) {
            return 'call_pharm_lab';
        }

        if ($salaryScaleCode === 'CM' && $level !== null && $level >= 1 && (str_contains($cadreKey, 'MEDICAL') || str_contains($cadreKey, 'REGISTRARMO'))) {
            return 'call_doctor';
        }

        return null;
    }

    protected function hasLegacyCallAllowanceSignal(array $legacyRow, ?array $masterRow): bool
    {
        foreach ([
            $legacyRow['call'] ?? null,
            $legacyRow['call_'] ?? null,
            $legacyRow['call_initial'] ?? null,
            $legacyRow['call_value'] ?? null,
            $legacyRow['call_allowance'] ?? null,
            $legacyRow['actual_call_allowance'] ?? null,
            $masterRow['call_allowance'] ?? null,
            $masterRow['actual_call_allowance'] ?? null,
        ] as $candidate) {
            if ($this->truthy($candidate) || in_array($this->cleanCode($candidate), ['CALLDOC', 'CALLPHARMLAB', 'CALLOPTODD', 'CALLNURSEOTHERS'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function parseDate(mixed $value, string $field, array &$issues): ?string
    {
        $parsed = $this->dateParser->parse($value, $field);

        if ($parsed['warning']) {
            $issues[] = $this->warning($field, 'invalid_date', $parsed['warning']);
        }

        return $parsed['value'];
    }

    protected function makeLegacyCnoPsn(?string $cno, ?string $psn): ?string
    {
        if ($cno === null && $psn === null) {
            return null;
        }

        return trim(($cno ?? '').($psn ?? '')) ?: null;
    }

    protected function makeProvisionalStaffNumber(
        array $legacyRow,
        string $sourceTable,
        ?string $mdaCode,
        string $fullName,
        ?string $dateOfBirth,
    ): ?string {
        $sourceRow = $legacyRow['_upload_row'] ?? $legacyRow['id'] ?? null;

        if ($sourceRow === null && $fullName === '') {
            return null;
        }

        $identity = implode('|', [
            $sourceTable,
            $mdaCode ?? 'MDA',
            $sourceRow ?? '',
            Str::upper($fullName),
            $dateOfBirth ?? '',
        ]);

        return 'PROV-'.Str::upper($mdaCode ?? 'MDA').'-'.substr(hash('sha256', $identity), 0, 12);
    }

    protected function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        $clean = Str::upper($this->cleanString((string) $value) ?? '');

        return in_array($clean, ['1', 'YES', 'Y', 'TRUE', 'CALLDOC', 'CALL', 'ACTIVE'], true);
    }

    protected function toInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    protected function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    protected function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $string === '' ? null : $string;
    }

    protected function cleanCode(mixed $value): ?string
    {
        $string = $this->cleanString($value);

        if ($string === null) {
            return null;
        }

        return Str::upper(Str::of($string)->replaceMatches('/[^A-Z0-9]+/i', '')->value()) ?: null;
    }

    /**
     * @return array{normalized?: string, compact?: string, significant?: string}
     */
    protected function buildStationMatchKeys(?string $stationName): array
    {
        $keys = [];
        $normalized = $this->cleanString($stationName);

        if ($normalized === null) {
            return $keys;
        }

        $candidate = $this->normalizeStationAlias($normalized) ?? $normalized;

        $keys['normalized'] = strtolower($candidate);

        if ($compact = $this->cleanCode($candidate)) {
            $keys['compact'] = $compact;
        }

        if ($significant = $this->normalizeStationSignificantKey($candidate)) {
            $keys['significant'] = $significant;
        }

        return $keys;
    }

    protected function normalizeStationAlias(?string $stationName): ?string
    {
        $normalized = $this->cleanString($stationName);

        if ($normalized === null) {
            return null;
        }

        return match (Str::upper($normalized)) {
            'JBMANM', 'GH JBMANM' => str_replace('JBMANM', 'JBANM', $normalized),
            default => $normalized,
        };
    }

    protected function isHeadquartersAlias(string $stationName): bool
    {
        return in_array($this->cleanCode($stationName), ['MOH', 'HMB', 'HQ', 'HQTR', 'HQTRS'], true);
    }

    protected function scoreStationMatch(array $candidateKeys, ?string $stationName): int
    {
        $stationKeys = $this->buildStationMatchKeys($stationName);

        if ($stationKeys === []) {
            return 0;
        }

        if (($candidateKeys['normalized'] ?? null) !== null && ($stationKeys['normalized'] ?? null) === $candidateKeys['normalized']) {
            return 4;
        }

        if (($candidateKeys['compact'] ?? null) !== null && ($stationKeys['compact'] ?? null) === $candidateKeys['compact']) {
            return 3;
        }

        if (($candidateKeys['significant'] ?? null) !== null && ($stationKeys['significant'] ?? null) === $candidateKeys['significant']) {
            return 2;
        }

        if (
            ($candidateKeys['significant'] ?? null) !== null
            && ($stationKeys['compact'] ?? null) !== null
            && (
                str_contains($candidateKeys['compact'] ?? '', $stationKeys['significant'])
                || str_contains($stationKeys['compact'], $candidateKeys['significant'])
            )
        ) {
            return 1;
        }

        return 0;
    }

    protected function normalizeStationSignificantKey(?string $stationName): ?string
    {
        $normalized = $this->cleanString($stationName);

        if ($normalized === null) {
            return null;
        }

        preg_match_all('/[A-Z0-9]+/i', Str::upper($normalized), $matches);

        $tokens = array_values(array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => strlen($token) > 2
        ));

        if ($tokens === []) {
            return $this->cleanCode($normalized);
        }

        return implode('', $tokens);
    }

    /**
     * @return array{field: string, error_code: string, message: string, severity: string}
     */
    protected function warning(string $field, string $errorCode, string $message): array
    {
        return [
            'field' => $field,
            'error_code' => $errorCode,
            'message' => $message,
            'severity' => 'warning',
        ];
    }

    protected function resolveExpectedRetirementDate(
        ?string $legacyEdor,
        ?string $computedEdor,
        ?string $dateOfFirstAppointment,
        ?string $dateOfLastPromotion,
        bool $isRetired,
    ): ?string {
        if ($legacyEdor === null) {
            return $computedEdor;
        }

        if ($computedEdor === null) {
            return $legacyEdor;
        }

        if ($isRetired) {
            return $legacyEdor;
        }

        $legacyDate = Carbon::parse($legacyEdor);
        $computedDate = Carbon::parse($computedEdor);

        if ($dateOfFirstAppointment !== null && $legacyDate->lt(Carbon::parse($dateOfFirstAppointment))) {
            return $computedEdor;
        }

        if ($dateOfLastPromotion !== null && $legacyDate->lt(Carbon::parse($dateOfLastPromotion))) {
            return $computedEdor;
        }

        if ($legacyDate->lt(now()->subYear()) && $computedDate->gte(now())) {
            return $computedEdor;
        }

        if ($this->yearGap($legacyDate, $computedDate) >= 8 && $legacyDate->lt($computedDate)) {
            return $computedEdor;
        }

        return $legacyEdor;
    }

    protected function isRetiredByExpectedRetirementDate(?string $expectedRetirementDate): bool
    {
        if ($expectedRetirementDate === null) {
            return false;
        }

        return Carbon::parse($expectedRetirementDate)->lte(now()->endOfDay());
    }

    protected function yearGap(CarbonInterface $firstDate, CarbonInterface $secondDate): int
    {
        return abs($firstDate->diffInYears($secondDate));
    }
}
