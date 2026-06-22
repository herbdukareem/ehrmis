<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\QualificationScaleCeiling;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LegacyFoundationImportService
{
    /**
     * @var array<int, \App\Domain\Organization\Models\Mda>
     */
    protected array $mdaByLegacyId = [];

    /**
     * @var array<string, \App\Domain\Organization\Models\Mda>
     */
    protected array $mdaByAlias = [];

    /**
     * @var array<string, string>
     */
    protected array $departmentCodeLookup = [];

    /**
     * @var array<string, string>
     */
    protected array $usedEmails = [];

    /**
     * @var array<int, \App\Domain\Staff\Models\SalaryScale>
     */
    protected array $salaryScaleByLegacyId = [];

    /**
     * @var array<string, \App\Domain\Staff\Models\SalaryScale>
     */
    protected array $salaryScaleByAlias = [];

    /**
     * @var array<int, \App\Domain\Staff\Models\Cadre>
     */
    protected array $cadreByLegacyId = [];

    /**
     * @var array<string, \App\Domain\Staff\Models\Cadre>
     */
    protected array $cadreByComposite = [];

    /**
     * @var array<string, \App\Domain\Staff\Models\QualificationType>
     */
    protected array $qualificationTypeByCode = [];

    /**
     * @param  array{include_users?: bool, dry_run?: bool, default_password?: string, default_state?: string}  $options
     * @return array<string, mixed>
     */
    public function import(array $options = []): array
    {
        $includeUsers = (bool) ($options['include_users'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $defaultPassword = (string) ($options['default_password'] ?? 'password');
        $defaultState = trim((string) ($options['default_state'] ?? 'Niger')) ?: 'Niger';

        $summary = [
            'dry_run' => $dryRun,
            'mdas' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'departments' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'salary_scales' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'cadres' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'ranks' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'qualification_types' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'qualification_scale_ceilings' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'promotion_policies' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'locations' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'stations' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'users' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'warnings' => [],
            'errors' => [],
        ];

        $this->usedEmails = [];
        $this->qualificationTypeByCode = [];
        $this->loadDepartmentCodeLookup();

        DB::beginTransaction();

        try {
            $this->importMdas($summary);
            $this->importDepartments($summary);
            $this->importSalaryScales($summary);
            $this->importCadres($summary);
            $this->importRanks($summary);
            $this->importQualificationTypesAndCeilings($summary);
            $this->importPromotionPolicies($summary);
            $this->importLocations($summary, $defaultState);
            $this->importStations($summary);

            if ($includeUsers) {
                $this->seedRolesAndPermissions();
                $this->importUsers($summary, $defaultPassword);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $throwable) {
            DB::rollBack();
            $summary['errors'][] = $throwable->getMessage();
            throw $throwable;
        }

        return $summary;
    }

    protected function importMdas(array &$summary): void
    {
        $legacyMdas = DB::connection('legacy')
            ->table('tbl_mda')
            ->orderBy('id')
            ->get();

        foreach ($legacyMdas as $legacyMda) {
            $name = $this->cleanString($legacyMda->full_name ?: $legacyMda->mda);

            if ($name === null) {
                $summary['mdas']['skipped']++;
                $summary['warnings'][] = 'Skipped legacy MDA row '.$legacyMda->id.' because it has no usable name.';

                continue;
            }

            $mda = Mda::query()->updateOrCreate(
                ['code' => $this->cleanCode($legacyMda->code) ?? ('MDA-'.$legacyMda->id)],
                [
                    'name' => $name,
                    'description' => 'Imported from legacy table tbl_mda',
                    'status' => $legacyMda->status === '1' ? 'active' : 'inactive',
                ],
            );

            $this->recordMutationSummary($summary['mdas'], $mda->wasRecentlyCreated);
            $this->indexMda($mda, $legacyMda->id, [
                $legacyMda->mda,
                $legacyMda->full_name,
                $legacyMda->code,
            ]);
        }

        $supplementalMdas = DB::connection('legacy')
            ->table('staff_list')
            ->select('mda')
            ->whereNotNull('mda')
            ->where('mda', '!=', '')
            ->distinct()
            ->pluck('mda');

        foreach ($supplementalMdas as $legacyMdaName) {
            $normalizedAlias = $this->normalizeKey($legacyMdaName);

            if ($normalizedAlias === null || isset($this->mdaByAlias[$normalizedAlias])) {
                continue;
            }

            $name = $this->cleanString($legacyMdaName);

            if ($name === null) {
                continue;
            }

            $mda = Mda::query()->firstOrCreate(
                ['code' => $this->makeUniqueMdaCode($name)],
                [
                    'name' => $name,
                    'description' => 'Imported from distinct legacy staff_list.mda values',
                    'status' => 'active',
                ],
            );

            $this->recordMutationSummary($summary['mdas'], $mda->wasRecentlyCreated);
            $this->indexMda($mda, null, [$legacyMdaName]);
        }
    }

    protected function importDepartments(array &$summary): void
    {
        $legacyDepartments = DB::connection('legacy')
            ->table('staff_list')
            ->select('mda', 'department')
            ->where('status', '1')
            ->whereNotNull('mda')
            ->where('mda', '!=', '')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('mda')
            ->orderBy('department')
            ->get();

        foreach ($legacyDepartments as $legacyDepartment) {
            $mda = $this->resolveLegacyMda($legacyDepartment->mda);
            $departmentName = $this->cleanString($legacyDepartment->department);

            if (! $mda || $departmentName === null) {
                $summary['departments']['skipped']++;
                $summary['warnings'][] = 'Skipped department import for legacy MDA `'.$legacyDepartment->mda.'` and department `'.$legacyDepartment->department.'`.';

                continue;
            }

            $department = Department::query()->forMda($mda->id)->updateOrCreate(
                [
                    'name' => $departmentName,
                ],
                [
                    'mda_id' => $mda->id,
                    'code' => $this->resolveDepartmentCode($departmentName, $mda->id),
                    'description' => 'Imported from legacy staff_list and departments tables',
                    'status' => 'active',
                ],
            );

            $this->recordMutationSummary($summary['departments'], $department->wasRecentlyCreated);
        }
    }

    protected function importSalaryScales(array &$summary): void
    {
        $legacySalaryScales = DB::connection('legacy')
            ->table('tbl_salary_scale')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacySalaryScales as $legacySalaryScale) {
            $name = $this->cleanString($legacySalaryScale->salary_scale);
            $code = $this->cleanCode($legacySalaryScale->code);

            if ($name === null || $code === null) {
                $summary['salary_scales']['skipped']++;
                $summary['warnings'][] = 'Skipped salary scale row '.$legacySalaryScale->id.' because the name or code is missing.';

                continue;
            }

            $salaryScale = SalaryScale::query()
                ->where('legacy_id', $legacySalaryScale->id)
                ->orWhere('code', $code)
                ->first();

            $wasRecentlyCreated = ! $salaryScale;
            $salaryScale ??= new SalaryScale();
            $salaryScale->fill([
                'legacy_id' => $legacySalaryScale->id,
                'code' => $code,
                'name' => $name,
                'min_level' => (int) $legacySalaryScale->min_level,
                'max_level' => (int) $legacySalaryScale->max_level,
                'min_step' => (int) $legacySalaryScale->min_step,
                'max_step' => (int) $legacySalaryScale->max_step,
                'status' => $legacySalaryScale->status === '1' ? 'active' : 'inactive',
            ]);
            $salaryScale->save();

            $this->recordMutationSummary($summary['salary_scales'], $wasRecentlyCreated);
            $this->indexSalaryScale($salaryScale, $legacySalaryScale->id, [
                $legacySalaryScale->salary_scale,
                $legacySalaryScale->code,
            ]);
        }
    }

    protected function importCadres(array &$summary): void
    {
        $legacyCadres = DB::connection('legacy')
            ->table('tbl_cadre')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacyCadres as $legacyCadre) {
            $name = $this->cleanString($legacyCadre->cadre);

            if ($name === null) {
                $summary['cadres']['skipped']++;
                $summary['warnings'][] = 'Skipped cadre row '.$legacyCadre->id.' because the cadre name is missing.';

                continue;
            }

            $salaryScale = $this->resolveLegacySalaryScale($legacyCadre->salary_scale_id);

            if (! $salaryScale) {
                $summary['cadres']['skipped']++;
                $summary['warnings'][] = 'Skipped cadre `'.$name.'` because salary scale `'.$legacyCadre->salary_scale_id.'` could not be resolved.';

                continue;
            }

            $cadre = Cadre::query()
                ->where('legacy_id', $legacyCadre->id)
                ->orWhere(function ($query) use ($name, $salaryScale): void {
                    $query
                        ->where('name', $name)
                        ->where('salary_scale_id', $salaryScale->id);
                })
                ->first();

            $wasRecentlyCreated = ! $cadre;
            $cadre ??= new Cadre();
            $cadre->fill([
                'legacy_id' => $legacyCadre->id,
                'salary_scale_id' => $salaryScale->id,
                'department_id' => $this->resolveCadreDepartmentId($legacyCadre->department),
                'name' => $name,
                'legacy_department_name' => $this->cleanString($legacyCadre->department),
                'description' => 'Imported from legacy table tbl_cadre',
                'status' => $legacyCadre->status === '1' ? 'active' : 'inactive',
            ]);
            $cadre->save();

            $this->recordMutationSummary($summary['cadres'], $wasRecentlyCreated);
            $this->indexCadre($cadre, $legacyCadre->id);
        }
    }

    protected function importRanks(array &$summary): void
    {
        $legacyRanks = DB::connection('legacy')
            ->table('tbl_rank')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacyRanks as $legacyRank) {
            $name = $this->cleanString($legacyRank->rank);
            $cadre = $this->resolveLegacyCadre($legacyRank->cadre, $legacyRank->cadre_name, $legacyRank->salary_scale_id);
            $salaryScale = $this->resolveLegacySalaryScale($legacyRank->salary_scale_id, $legacyRank->salary_scale_code);

            if ($name === null || ! $cadre || ! $salaryScale) {
                $summary['ranks']['skipped']++;
                $summary['warnings'][] = 'Skipped rank row '.$legacyRank->id.' because the rank, cadre, or salary scale could not be resolved.';

                continue;
            }

            $rank = Rank::query()
                ->where('legacy_id', $legacyRank->id)
                ->orWhere(function ($query) use ($name, $cadre, $legacyRank): void {
                    $query
                        ->where('cadre_id', $cadre->id)
                        ->where('name', $name)
                        ->where('level', (int) $legacyRank->level);
                })
                ->first();

            $wasRecentlyCreated = ! $rank;
            $rank ??= new Rank();
            $rank->fill([
                'legacy_id' => $legacyRank->id,
                'cadre_id' => $cadre->id,
                'salary_scale_id' => $salaryScale->id,
                'name' => $name,
                'level' => $legacyRank->level !== null ? (int) $legacyRank->level : null,
                'description' => 'Imported from legacy table tbl_rank',
                'status' => $legacyRank->status === '1' ? 'active' : 'inactive',
            ]);
            $rank->save();

            $this->recordMutationSummary($summary['ranks'], $wasRecentlyCreated);
        }
    }

    protected function importQualificationTypesAndCeilings(array &$summary): void
    {
        $legacyQualifications = DB::connection('legacy')
            ->table('certificate_bar')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacyQualifications as $legacyQualification) {
            $qualificationName = $this->cleanString($legacyQualification->certificate);

            if ($qualificationName === null) {
                $summary['qualification_types']['skipped']++;
                $summary['warnings'][] = 'Skipped qualification row '.$legacyQualification->id.' because the certificate name is missing.';

                continue;
            }

            $qualificationCode = $this->makeQualificationCode($qualificationName);
            $qualificationType = QualificationType::query()->firstOrNew(['code' => $qualificationCode]);
            $wasRecentlyCreated = ! $qualificationType->exists;
            $qualificationType->fill([
                'name' => $qualificationName,
                'description' => 'Imported from legacy table certificate_bar',
                'status' => $legacyQualification->status === '1' ? 'active' : 'inactive',
            ]);
            $qualificationType->save();

            $this->recordMutationSummary($summary['qualification_types'], $wasRecentlyCreated);
            $this->qualificationTypeByCode[$qualificationCode] = $qualificationType;

            foreach (['CH', 'GL', 'CM', 'SG'] as $scaleCode) {
                $maxLevel = (int) ($legacyQualification->{$scaleCode} ?? 0);

                if ($maxLevel <= 0) {
                    $summary['qualification_scale_ceilings']['skipped']++;
                    continue;
                }

                $salaryScale = $this->resolveLegacySalaryScale($scaleCode, $scaleCode);

                if (! $salaryScale) {
                    $summary['qualification_scale_ceilings']['skipped']++;
                    $summary['warnings'][] = 'Skipped qualification ceiling for `'.$qualificationName.'` because salary scale `'.$scaleCode.'` was not found.';

                    continue;
                }

                $ceiling = QualificationScaleCeiling::query()->updateOrCreate(
                    [
                        'qualification_type_id' => $qualificationType->id,
                        'salary_scale_id' => $salaryScale->id,
                    ],
                    [
                        'max_level' => $maxLevel,
                        'status' => $legacyQualification->status === '1' ? 'active' : 'inactive',
                    ],
                );

                $this->recordMutationSummary($summary['qualification_scale_ceilings'], $ceiling->wasRecentlyCreated);
            }
        }
    }

    protected function importPromotionPolicies(array &$summary): void
    {
        $legacyPolicies = DB::connection('legacy')
            ->table('promotion_years')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacyPolicies as $legacyPolicy) {
            $salaryScale = $this->resolveLegacySalaryScale($legacyPolicy->scale, $legacyPolicy->scale);

            if (! $salaryScale) {
                $summary['promotion_policies']['skipped']++;
                $summary['warnings'][] = 'Skipped promotion policy row '.$legacyPolicy->id.' because salary scale `'.$legacyPolicy->scale.'` was not found.';

                continue;
            }

            $policy = PromotionPolicy::query()->updateOrCreate(
                [
                    'salary_scale_id' => $salaryScale->id,
                    'min_level' => (int) $legacyPolicy->min_level,
                    'max_level' => (int) $legacyPolicy->max_level,
                    'policy_type' => 'normal',
                ],
                [
                    'required_years' => (int) $legacyPolicy->year,
                    'description' => 'Imported from legacy table promotion_years',
                    'status' => $legacyPolicy->status === '1' ? 'active' : 'inactive',
                ],
            );

            $this->recordMutationSummary($summary['promotion_policies'], $policy->wasRecentlyCreated);
        }
    }

    protected function importLocations(array &$summary, string $defaultState): void
    {
        $legacyLocations = collect();

        $legacyLocations = $legacyLocations->concat(
            DB::connection('legacy')
                ->table('tbl_stations')
                ->select('location as town', 'lga_name as lga')
                ->where('status', '1')
                ->get()
        );

        $legacyLocations = $legacyLocations->concat(
            DB::connection('legacy')
                ->table('staff_list')
                ->select('location as town', 'location as lga')
                ->where('status', '1')
                ->whereNotNull('location')
                ->where('location', '!=', '')
                ->distinct()
                ->get()
        );

        $legacyLocations
            ->unique(fn ($location) => $this->normalizeKey($location->town).'|'.$this->normalizeKey($location->lga))
            ->each(function ($legacyLocation) use (&$summary, $defaultState): void {
                $town = $this->cleanString($legacyLocation->town);
                $lga = $this->cleanString($legacyLocation->lga);

                if ($town === null || $lga === null) {
                    $summary['locations']['skipped']++;

                    return;
                }

                $location = Location::query()->updateOrCreate(
                    [
                        'state' => $defaultState,
                        'lga' => $lga,
                        'ward' => null,
                        'town' => $town,
                    ],
                    [
                        'is_urban_center' => in_array($this->normalizeKey($town), ['MINNA', 'BIDA', 'SULEJA', 'KONTAGORA'], true),
                        'status' => 'active',
                    ],
                );

                $this->recordMutationSummary($summary['locations'], $location->wasRecentlyCreated);
            });
    }

    protected function importStations(array &$summary): void
    {
        $stationMdas = $this->buildStationMdaLookup();

        $legacyStations = DB::connection('legacy')
            ->table('tbl_stations')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacyStations as $legacyStation) {
            $stationName = $this->cleanString($legacyStation->station);

            if ($stationName === null) {
                $summary['stations']['skipped']++;
                continue;
            }

            $mda = $this->resolveStationMdaFromLookup($stationMdas, $stationName)
                ?? $this->resolveMdaFromStationName($stationName);

            if (! $mda) {
                $summary['stations']['skipped']++;
                $summary['warnings'][] = 'Skipped station `'.$stationName.'` because no matching MDA could be resolved from legacy staff data.';

                continue;
            }

            $station = Station::query()->forMda($mda->id)->updateOrCreate(
                [
                    'name' => $stationName,
                ],
                [
                    'mda_id' => $mda->id,
                    'code' => $this->makeStationCode($legacyStation->id, $stationName),
                    'description' => $this->buildStationDescription($legacyStation),
                    'status' => 'active',
                ],
            );

            $this->recordMutationSummary($summary['stations'], $station->wasRecentlyCreated);
        }
    }

    protected function importUsers(array &$summary, string $defaultPassword): void
    {
        $legacyUsers = DB::connection('legacy')
            ->table('users')
            ->where('status', '1')
            ->orderBy('id')
            ->get();

        foreach ($legacyUsers as $legacyUser) {
            $email = $this->resolveUserEmail($legacyUser);
            $userType = $this->determineUserType($legacyUser->role, $legacyUser->access);
            $mda = $this->resolveLegacyMda($legacyUser->mda);

            if (! $userType->value || (! $userType && ! $mda)) {
                $summary['users']['skipped']++;
                continue;
            }

            if (! $this->isGlobalUserType($userType) && ! $mda) {
                $summary['users']['skipped']++;
                $summary['warnings'][] = 'Skipped legacy user `'.$legacyUser->email.'` because the assigned MDA `'.$legacyUser->mda.'` could not be resolved.';

                continue;
            }

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'mda_id' => $this->isGlobalUserType($userType) ? null : $mda?->id,
                    'name' => $this->buildUserName($legacyUser),
                    'password' => Hash::make($defaultPassword),
                    'user_type' => $userType->value,
                    'status' => $legacyUser->status === '1' ? 'active' : 'inactive',
                    'email_verified_at' => $this->normalizeTimestamp($legacyUser->email_verified_at),
                ],
            );

            $user->syncRoles([$this->mapRoleName($userType)]);

            $this->recordMutationSummary($summary['users'], $user->wasRecentlyCreated);
            $this->usedEmails[strtolower($email)] = $email;
        }
    }

    protected function seedRolesAndPermissions(): void
    {
        app(RolesAndPermissionsSeeder::class)->run();
    }

    protected function loadDepartmentCodeLookup(): void
    {
        $legacyDepartments = DB::connection('legacy')
            ->table('departments')
            ->where('status', '1')
            ->get();

        foreach ($legacyDepartments as $legacyDepartment) {
            $name = $this->normalizeKey($legacyDepartment->department);
            $code = $this->cleanCode($legacyDepartment->department_code);

            if ($name !== null && $code !== null) {
                $this->departmentCodeLookup[$name] = $code;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function buildStationMdaLookup(): array
    {
        $lookup = [];

        $assignments = DB::connection('legacy')
            ->table('staff_list')
            ->select('station', 'mda', DB::raw('COUNT(*) as aggregate_count'))
            ->where('status', '1')
            ->whereNotNull('station')
            ->where('station', '!=', '')
            ->whereNotNull('mda')
            ->where('mda', '!=', '')
            ->groupBy('station', 'mda')
            ->orderBy('station')
            ->orderByDesc('aggregate_count')
            ->get();

        foreach ($assignments as $assignment) {
            foreach ($this->buildStationLookupKeys($assignment->station) as $key) {
                if (! isset($lookup[$key])) {
                    $lookup[$key] = (string) $assignment->mda;
                }
            }
        }

        return $lookup;
    }

    protected function resolveStationMdaFromLookup(array $lookup, string $stationName): ?Mda
    {
        foreach ($this->buildStationLookupKeys($stationName) as $key) {
            if (isset($lookup[$key])) {
                return $this->resolveLegacyMda($lookup[$key]);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function buildStationLookupKeys(mixed $stationName): array
    {
        $keys = [];

        foreach ([
            $this->normalizeKey($stationName),
            $this->normalizeCompactKey($stationName),
            $this->normalizeStationSignificantKey($stationName),
        ] as $key) {
            if ($key !== null) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    protected function resolveLegacyMda(null|int|string $legacyValue): ?Mda
    {
        if ($legacyValue === null || $legacyValue === '') {
            return null;
        }

        if (is_numeric($legacyValue) && isset($this->mdaByLegacyId[(int) $legacyValue])) {
            return $this->mdaByLegacyId[(int) $legacyValue];
        }

        return $this->mdaByAlias[$this->normalizeKey((string) $legacyValue)] ?? null;
    }

    protected function resolveMdaFromStationName(string $stationName): ?Mda
    {
        $directMatch = $this->resolveLegacyMda($stationName);

        if ($directMatch) {
            return $directMatch;
        }

        $stationKey = $this->normalizeCompactKey($stationName);

        if ($stationKey === null) {
            return null;
        }

        $bestMatch = null;
        $bestLength = 0;

        foreach ($this->mdaByAlias as $alias => $mda) {
            $aliasKey = $this->normalizeCompactKey($alias);

            if ($aliasKey === null || strlen($aliasKey) < 3) {
                continue;
            }

            if (
                (str_starts_with($stationKey, $aliasKey) || str_contains($stationKey, $aliasKey))
                && strlen($aliasKey) > $bestLength
            ) {
                $bestMatch = $mda;
                $bestLength = strlen($aliasKey);
            }
        }

        return $bestMatch;
    }

    protected function indexMda(Mda $mda, ?int $legacyId, array $aliases): void
    {
        if ($legacyId !== null) {
            $this->mdaByLegacyId[$legacyId] = $mda;
        }

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizeKey($alias);

            if ($normalizedAlias !== null) {
                $this->mdaByAlias[$normalizedAlias] = $mda;
            }
        }

        if ($this->normalizeKey($mda->name) !== null) {
            $this->mdaByAlias[$this->normalizeKey($mda->name)] = $mda;
        }

        if ($this->normalizeKey($mda->code) !== null) {
            $this->mdaByAlias[$this->normalizeKey($mda->code)] = $mda;
        }
    }

    protected function resolveLegacySalaryScale(null|int|string $legacyValue, ?string $fallbackCode = null): ?SalaryScale
    {
        if ($legacyValue !== null && $legacyValue !== '' && is_numeric($legacyValue) && isset($this->salaryScaleByLegacyId[(int) $legacyValue])) {
            return $this->salaryScaleByLegacyId[(int) $legacyValue];
        }

        $codeKey = $this->normalizeKey($fallbackCode ?? $legacyValue);

        if ($codeKey !== null && isset($this->salaryScaleByAlias[$codeKey])) {
            return $this->salaryScaleByAlias[$codeKey];
        }

        return null;
    }

    protected function indexSalaryScale(SalaryScale $salaryScale, ?int $legacyId, array $aliases): void
    {
        if ($legacyId !== null) {
            $this->salaryScaleByLegacyId[$legacyId] = $salaryScale;
        }

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizeKey($alias);

            if ($normalizedAlias !== null) {
                $this->salaryScaleByAlias[$normalizedAlias] = $salaryScale;
            }
        }
    }

    protected function resolveLegacyCadre(null|int|string $legacyValue, ?string $fallbackName = null, null|int|string $salaryScaleId = null): ?Cadre
    {
        if ($legacyValue !== null && $legacyValue !== '' && is_numeric($legacyValue) && isset($this->cadreByLegacyId[(int) $legacyValue])) {
            return $this->cadreByLegacyId[(int) $legacyValue];
        }

        $name = $this->cleanString($fallbackName);
        $salaryScale = $this->resolveLegacySalaryScale($salaryScaleId);

        if ($name === null || ! $salaryScale) {
            return null;
        }

        return $this->cadreByComposite[$this->makeCadreCompositeKey($name, $salaryScale->id)] ?? null;
    }

    protected function indexCadre(Cadre $cadre, ?int $legacyId): void
    {
        if ($legacyId !== null) {
            $this->cadreByLegacyId[$legacyId] = $cadre;
        }

        $this->cadreByComposite[$this->makeCadreCompositeKey($cadre->name, $cadre->salary_scale_id)] = $cadre;
    }

    protected function resolveDepartmentCode(string $departmentName, int $mdaId): string
    {
        $normalizedName = $this->normalizeKey($departmentName) ?? 'DEPARTMENT';
        $baseCode = $this->departmentCodeLookup[$normalizedName] ?? Str::upper(Str::slug($departmentName, '_'));
        $baseCode = trim($baseCode) !== '' ? trim($baseCode) : 'DEPARTMENT';

        $code = $baseCode;
        $counter = 1;

        while (
            Department::query()
                ->forMda($mdaId)
                ->where('code', $code)
                ->where('name', '!=', $departmentName)
                ->exists()
        ) {
            $counter++;
            $code = Str::limit($baseCode, 42, '').'_'.$counter;
        }

        return $code;
    }

    protected function resolveCadreDepartmentId(?string $departmentName): ?int
    {
        $name = $this->cleanString($departmentName);

        if ($name === null) {
            return null;
        }

        $matches = Department::withoutGlobalScopes()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->pluck('id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    protected function makeStationCode(int $legacyId, string $stationName): string
    {
        $base = Str::upper(Str::slug($stationName, '_'));
        $base = trim($base) !== '' ? $base : 'STATION';

        return Str::limit($base, 40, '').'_'.str_pad((string) $legacyId, 4, '0', STR_PAD_LEFT);
    }

    protected function makeUniqueMdaCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '_'));
        $base = trim($base) !== '' ? $base : 'MDA';
        $code = Str::limit($base, 45, '');
        $counter = 1;

        while (Mda::query()->where('code', $code)->exists()) {
            $counter++;
            $code = Str::limit($base, 40, '').'_'.$counter;
        }

        return $code;
    }

    protected function buildStationDescription(object $legacyStation): ?string
    {
        $parts = array_filter([
            $this->cleanString($legacyStation->location),
            $this->cleanString($legacyStation->lga_name),
            $this->cleanString($legacyStation->address),
        ]);

        return $parts === [] ? null : implode(' | ', $parts);
    }

    protected function determineUserType(?string $legacyRole, ?string $legacyAccess): UserType
    {
        $role = $this->normalizeKey($legacyRole) ?? '';
        $access = $this->normalizeKey($legacyAccess) ?? '';

        if ($access === 'MIS' || $role === 'SUPER_ADMIN') {
            return UserType::SUPER_ADMIN;
        }

        if (in_array($role, ['SYSTEM ADMINISTRATOR', 'DIRECTOR', 'CEO'], true)) {
            return UserType::MDA_ADMIN;
        }

        return UserType::REPORT_VIEWER;
    }

    protected function mapRoleName(UserType $userType): string
    {
        return match ($userType) {
            UserType::SUPER_ADMIN => 'Super Admin',
            UserType::MIS_ADMIN => 'MIS Admin',
            UserType::MDA_ADMIN => 'MDA Admin',
            UserType::HR_OFFICER => 'HR Officer',
            UserType::BUDGET_OFFICER => 'Budget Officer',
            UserType::PAYROLL_AUDITOR => 'Payroll Auditor',
            UserType::APPROVAL_OFFICER => 'Approval Officer',
            default => 'Report Viewer',
        };
    }

    protected function isGlobalUserType(UserType $userType): bool
    {
        return in_array($userType, [UserType::SUPER_ADMIN, UserType::MIS_ADMIN], true);
    }

    protected function buildUserName(object $legacyUser): string
    {
        $parts = array_filter([
            $this->cleanString($legacyUser->first_name),
            $this->cleanString($legacyUser->other_name),
            $this->cleanString($legacyUser->surname),
        ]);

        return $parts === [] ? ('Legacy User '.$legacyUser->id) : implode(' ', $parts);
    }

    protected function resolveUserEmail(object $legacyUser): string
    {
        $candidate = strtolower(trim((string) $legacyUser->email));
        $validator = Validator::make(['email' => $candidate], ['email' => ['nullable', 'email']]);

        if ($candidate === '' || $validator->fails() || isset($this->usedEmails[$candidate])) {
            $base = Str::slug((string) ($legacyUser->userId ?: 'legacy-user-'.$legacyUser->id), '.');
            $candidate = $base.'@legacy-import.local';
        }

        $counter = 1;
        $resolved = $candidate;

        while (isset($this->usedEmails[$resolved])) {
            $resolved = preg_replace('/@/', '+'.$counter.'@', $candidate, 1) ?: ('legacy-user-'.$legacyUser->id.'-'.$counter.'@legacy-import.local');
            $counter++;
        }

        return $resolved;
    }

    protected function normalizeTimestamp(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        if (! $value || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
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

        $code = Str::upper(Str::of($string)->replaceMatches('/[^A-Z0-9\/_-]+/i', '')->value());

        return $code === '' ? null : $code;
    }

    protected function normalizeKey(mixed $value): ?string
    {
        $string = $this->cleanString($value);

        return $string === null ? null : Str::upper($string);
    }

    protected function normalizeCompactKey(mixed $value): ?string
    {
        $string = $this->cleanString($value);

        if ($string === null) {
            return null;
        }

        $compact = Str::upper(preg_replace('/[^A-Z0-9]+/i', '', $string) ?? '');

        return $compact === '' ? null : $compact;
    }

    protected function normalizeStationSignificantKey(mixed $value): ?string
    {
        $string = $this->cleanString($value);

        if ($string === null) {
            return null;
        }

        preg_match_all('/[A-Z0-9]+/i', Str::upper($string), $matches);

        $tokens = array_values(array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => strlen($token) > 2
        ));

        if ($tokens === []) {
            return $this->normalizeCompactKey($string);
        }

        return implode('', $tokens);
    }

    protected function makeQualificationCode(string $qualificationName): string
    {
        return Str::upper(Str::slug($qualificationName, '_'));
    }

    protected function recordMutationSummary(array &$bucket, bool $wasRecentlyCreated): void
    {
        if ($wasRecentlyCreated) {
            $bucket['created']++;
        } else {
            $bucket['updated']++;
        }
    }

    protected function makeCadreCompositeKey(string $cadreName, ?int $salaryScaleId): string
    {
        return ($this->normalizeKey($cadreName) ?? $cadreName).'|'.($salaryScaleId ?? 0);
    }
}
