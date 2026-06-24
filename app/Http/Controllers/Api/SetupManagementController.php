<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Http\Controllers\Controller;
use App\Support\SetupManagementRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SetupManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(SetupManagementRules::canViewPage($user), 403);

        $departments = $this->visibleDepartmentQuery($user)
            ->orderBy('name')
            ->get(['id', 'mda_id', 'code', 'name', 'description', 'status']);

        $cadres = $this->visibleCadreQuery($user)
            ->with(['department:id,mda_id,code,name', 'salaryScale:id,code,name'])
            ->orderBy('name')
            ->get(['id', 'department_id', 'salary_scale_id', 'name', 'description', 'status']);

        $rates = SalaryStructureRate::query()
            ->with(['salaryScale:id,code,name'])
            ->orderBy('salary_scale_id')
            ->orderBy('level')
            ->orderBy('step')
            ->get([
                'id',
                'salary_scale_id',
                'level',
                'step',
                'basic_salary',
                'legacy_gross_salary',
                'status',
                'effective_from',
                'effective_to',
            ]);

        return response()->json([
            'data' => [
                'decisions' => SetupManagementRules::decisions(),
                'permissions' => [
                    'manage_departments' => $user->can('manage-departments'),
                    'manage_stations' => $user->can('manage-stations'),
                    'manage_cadres' => $user->can('manage-cadres'),
                    'manage_ranks' => $user->can('manage-ranks'),
                    'manage_allowance_types' => SetupManagementRules::canManageMdaOwnedSetup($user, 'manage-allowance-types'),
                    'manage_salary_scales' => SetupManagementRules::canManageMdaOwnedSetup($user, 'manage-salary-scales'),
                    'manage_qualification_types' => SetupManagementRules::canManageMdaOwnedSetup($user, 'manage-qualification-types'),
                    'manage_salary_structure' => SetupManagementRules::canManageMdaOwnedSetup($user, 'manage-salary-structure'),
                ],
                'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name']),
                'departments' => $departments,
                'stations' => $this->visibleStationQuery($user)->orderBy('name')->get(['id', 'mda_id', 'code', 'name', 'description', 'status']),
                'cadres' => $cadres->map(fn (Cadre $cadre): array => [
                    'id' => $cadre->id,
                    'department_id' => $cadre->department_id,
                    'salary_scale_id' => $cadre->salary_scale_id,
                    'name' => $cadre->name,
                    'description' => $cadre->description,
                    'status' => $cadre->status,
                    'department' => $cadre->department?->only(['id', 'mda_id', 'code', 'name']),
                    'salary_scale' => $cadre->salaryScale?->only(['id', 'code', 'name']),
                ])->values(),
                'ranks' => $this->visibleRankQuery($user)
                    ->with([
                        'cadre:id,department_id,salary_scale_id,name',
                        'cadre.department:id,mda_id,code,name',
                        'salaryScale:id,code,name',
                    ])
                    ->orderBy('name')
                    ->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level', 'description', 'status'])
                    ->map(fn (Rank $rank): array => [
                        'id' => $rank->id,
                        'cadre_id' => $rank->cadre_id,
                        'salary_scale_id' => $rank->salary_scale_id,
                        'name' => $rank->name,
                        'level' => $rank->level,
                        'description' => $rank->description,
                        'status' => $rank->status,
                        'cadre' => [
                            'id' => $rank->cadre?->id,
                            'name' => $rank->cadre?->name,
                            'department' => $rank->cadre?->department?->only(['id', 'mda_id', 'code', 'name']),
                        ],
                        'salary_scale' => $rank->salaryScale?->only(['id', 'code', 'name']),
                    ])->values(),
                'allowance_types' => AllowanceType::query()->orderBy('name')->get(['id', 'mda_id', 'code', 'name', 'description', 'status']),
                'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'mda_id', 'code', 'name', 'min_level', 'max_level', 'min_step', 'max_step', 'status']),
                'qualification_types' => QualificationType::query()->orderBy('name')->get(['id', 'mda_id', 'code', 'name', 'description', 'status']),
                'salary_structure_rates' => $rates->map(fn (SalaryStructureRate $rate): array => [
                    'id' => $rate->id,
                    'mda_id' => $rate->mda_id,
                    'salary_scale_id' => $rate->salary_scale_id,
                    'level' => $rate->level,
                    'step' => $rate->step,
                    'basic_salary' => $rate->basic_salary,
                    'legacy_gross_salary' => $rate->legacy_gross_salary,
                    'status' => $rate->status,
                    'effective_from' => optional($rate->effective_from)?->toDateString(),
                    'effective_to' => optional($rate->effective_to)?->toDateString(),
                    'salary_scale' => $rate->salaryScale?->only(['id', 'code', 'name']),
                ])->values(),
                'salary_structure_rate_allowances' => SalaryStructureRateAllowance::query()
                    ->with([
                        'salaryStructureRate.salaryScale:id,code,name',
                        'allowanceType:id,code,name',
                    ])
                    ->orderBy('salary_structure_rate_id')
                    ->get(['id', 'salary_structure_rate_id', 'allowance_type_id', 'amount', 'status'])
                    ->map(fn (SalaryStructureRateAllowance $allowance): array => [
                        'id' => $allowance->id,
                        'mda_id' => $allowance->mda_id,
                        'salary_structure_rate_id' => $allowance->salary_structure_rate_id,
                        'allowance_type_id' => $allowance->allowance_type_id,
                        'amount' => $allowance->amount,
                        'status' => $allowance->status,
                        'salary_structure_rate' => $allowance->salaryStructureRate
                            ? [
                                'id' => $allowance->salaryStructureRate->id,
                                'level' => $allowance->salaryStructureRate->level,
                                'step' => $allowance->salaryStructureRate->step,
                                'salary_scale' => $allowance->salaryStructureRate->salaryScale?->only(['id', 'code', 'name']),
                            ]
                            : null,
                        'allowance_type' => $allowance->allowanceType?->only(['id', 'code', 'name']),
                    ])->values(),
            ],
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $config = $this->config($type);
        $validated = $this->validatePayload($request, $config);

        $record = DB::transaction(function () use ($config, $validated): Model {
            $modelClass = $config['model'];
            $record = new $modelClass();

            $this->assertManageable($config, request()->user(), $validated, $record);
            $record->fill($this->normalizePayload($config, $validated));
            $record->save();

            return $record;
        });

        return response()->json([
            'message' => $config['label'].' created.',
            'data' => $this->serializeRecord($type, $record->fresh()),
        ], 201);
    }

    public function update(Request $request, string $type, int $recordId): JsonResponse
    {
        $config = $this->config($type);
        $record = $this->findRecord($request->user(), $config, $recordId);
        $validated = $this->validatePayload($request, $config, $record);

        DB::transaction(function () use ($config, $validated, $record, $request): void {
            $this->assertManageable($config, $request->user(), $validated, $record);
            $record->fill($this->normalizePayload($config, $validated, $record));
            $record->save();
        });

        return response()->json([
            'message' => $config['label'].' updated.',
            'data' => $this->serializeRecord($type, $record->fresh()),
        ]);
    }

    public function destroy(Request $request, string $type, int $recordId): JsonResponse
    {
        $config = $this->config($type);
        $record = $this->findRecord($request->user(), $config, $recordId);
        $this->assertManageable($config, $request->user(), $this->payloadForRecord($config, $record), $record);
        $record->delete();

        return response()->json(['message' => $config['label'].' deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(string $type): array
    {
        return match ($type) {
            'departments' => [
                'type' => $type,
                'label' => 'Department',
                'model' => Department::class,
                'permission' => 'manage-departments',
                'scope' => 'mda-direct',
            ],
            'stations' => [
                'type' => $type,
                'label' => 'Station',
                'model' => Station::class,
                'permission' => 'manage-stations',
                'scope' => 'mda-direct',
            ],
            'cadres' => [
                'type' => $type,
                'label' => 'Cadre',
                'model' => Cadre::class,
                'permission' => 'manage-cadres',
                'scope' => 'mda-department',
            ],
            'ranks' => [
                'type' => $type,
                'label' => 'Rank',
                'model' => Rank::class,
                'permission' => 'manage-ranks',
                'scope' => 'mda-cadre',
            ],
            'allowance-types' => [
                'type' => $type,
                'label' => 'Allowance type',
                'model' => AllowanceType::class,
                'permission' => 'manage-allowance-types',
                'scope' => 'mda-direct',
            ],
            'salary-scales' => [
                'type' => $type,
                'label' => 'Salary scale',
                'model' => SalaryScale::class,
                'permission' => 'manage-salary-scales',
                'scope' => 'mda-direct',
            ],
            'qualification-types' => [
                'type' => $type,
                'label' => 'Qualification type',
                'model' => QualificationType::class,
                'permission' => 'manage-qualification-types',
                'scope' => 'mda-direct',
            ],
            'salary-structure-rates' => [
                'type' => $type,
                'label' => 'Salary structure rate',
                'model' => SalaryStructureRate::class,
                'permission' => 'manage-salary-structure',
                'scope' => 'mda-direct',
            ],
            'salary-structure-rate-allowances' => [
                'type' => $type,
                'label' => 'Salary structure allowance',
                'model' => SalaryStructureRateAllowance::class,
                'permission' => 'manage-salary-structure',
                'scope' => 'mda-direct',
            ],
            default => abort(404),
        };
    }

    protected function findRecord($user, array $config, int $recordId): Model
    {
        return $this->baseQuery($user, $config)->findOrFail($recordId);
    }

    protected function baseQuery($user, array $config): Builder
    {
        /** @var Builder $query */
        $query = ($config['model'])::query();

        return match ($config['scope']) {
            'mda-direct' => $query,
            'mda-department' => $query->visibleToUser($user),
            'mda-cadre' => $query->visibleToUser($user),
            'global' => $query,
            default => $query->whereRaw('1 = 0'),
        };
    }

    protected function visibleDepartmentQuery($user): Builder
    {
        return Department::query();
    }

    protected function visibleStationQuery($user): Builder
    {
        return Station::query();
    }

    protected function visibleCadreQuery($user): Builder
    {
        return Cadre::query()->visibleToUser($user);
    }

    protected function visibleRankQuery($user): Builder
    {
        return Rank::query()->visibleToUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request, array $config, ?Model $record = null): array
    {
        return match ($config['type']) {
            'departments', 'stations' => $request->validate([
                'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
                'code' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique($record?->getTable() ?? $config['type'], 'code')
                        ->ignore($record?->getKey())
                        ->where(fn ($query) => $query->where('mda_id', $request->integer('mda_id') ?: $request->user()->mda_id)),
                ],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]),
            'cadres' => $request->validate([
                'department_id' => ['required', 'integer', 'exists:departments,id'],
                'salary_scale_id' => ['required', 'integer', 'exists:salary_scales,id'],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('cadres', 'name')
                        ->ignore($record?->getKey())
                        ->where(fn ($query) => $query
                            ->where('department_id', $request->integer('department_id'))
                            ->where('salary_scale_id', $request->integer('salary_scale_id'))),
                ],
                'description' => ['nullable', 'string'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]),
            'ranks' => $request->validate([
                'cadre_id' => ['required', 'integer', 'exists:cadres,id'],
                'salary_scale_id' => ['required', 'integer', 'exists:salary_scales,id'],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('ranks', 'name')
                        ->ignore($record?->getKey())
                        ->where(fn ($query) => $query
                            ->where('cadre_id', $request->integer('cadre_id'))
                            ->where('salary_scale_id', $request->integer('salary_scale_id'))
                            ->where('level', $request->integer('level'))),
                ],
                'level' => ['required', 'integer', 'min:0'],
                'description' => ['nullable', 'string'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]),
            'allowance-types', 'qualification-types' => $request->validate([
                'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
                'code' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique(($config['model'])::query()->getModel()->getTable(), 'code')
                        ->ignore($record?->getKey())
                        ->where(fn ($query) => $query->where('mda_id', $request->integer('mda_id') ?: $record?->mda_id ?: $request->user()->mda_id)),
                ],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]),
            'salary-scales' => $request->validate([
                'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
                'code' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('salary_scales', 'code')
                        ->ignore($record?->getKey())
                        ->where(fn ($query) => $query->where('mda_id', $request->integer('mda_id') ?: $record?->mda_id ?: $request->user()->mda_id)),
                ],
                'name' => ['required', 'string', 'max:255'],
                'min_level' => ['required', 'integer', 'min:0'],
                'max_level' => ['required', 'integer', 'min:0'],
                'min_step' => ['required', 'integer', 'min:0'],
                'max_step' => ['required', 'integer', 'min:0'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]),
            'salary-structure-rates' => $request->validate([
                'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
                'salary_scale_id' => ['required', 'integer', 'exists:salary_scales,id'],
                'level' => ['required', 'integer', 'min:0'],
                'step' => ['required', 'integer', 'min:0'],
                'basic_salary' => ['required', 'numeric', 'min:0'],
                'legacy_gross_salary' => ['nullable', 'numeric', 'min:0'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'effective_from' => ['nullable', 'date'],
                'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            ]),
            'salary-structure-rate-allowances' => $request->validate([
                'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
                'salary_structure_rate_id' => ['required', 'integer', 'exists:salary_structure_rates,id'],
                'allowance_type_id' => ['required', 'integer', 'exists:allowance_types,id'],
                'amount' => ['required', 'numeric', 'min:0'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]),
            default => [],
        };
    }

    protected function assertManageable(array $config, $user, array $validated, ?Model $record = null): void
    {
        $mdaId = match ($config['scope']) {
            'mda-direct' => $this->resolveManagedMdaId($config, $validated, $record, $user),
            'mda-department' => (int) optional(Department::query()->find($validated['department_id'] ?? $record?->department_id))->mda_id,
            'mda-cadre' => (int) optional(optional(Cadre::query()->with('department')->find($validated['cadre_id'] ?? $record?->cadre_id))->department)->mda_id,
            default => 0,
        };

        abort_unless($mdaId > 0 && SetupManagementRules::canManageMdaOwnedSetup($user, $config['permission'], $mdaId), 403);
        $this->assertMdaIntegrity($config, $validated, $record);
    }

    protected function assertMdaIntegrity(array $config, array $validated, ?Model $record = null): void
    {
        if ($config['type'] === 'salary-scales') {
            if ((int) $validated['min_level'] > (int) $validated['max_level']) {
                throw ValidationException::withMessages([
                    'min_level' => 'Minimum level cannot be greater than maximum level.',
                ]);
            }

            if ((int) $validated['min_step'] > (int) $validated['max_step']) {
                throw ValidationException::withMessages([
                    'min_step' => 'Minimum step cannot be greater than maximum step.',
                ]);
            }

            return;
        }

        if ($config['type'] === 'ranks') {
            $cadre = Cadre::query()->with('salaryScale')->findOrFail($validated['cadre_id'] ?? $record?->cadre_id);

            if ((int) ($validated['salary_scale_id'] ?? $record?->salary_scale_id) !== (int) $cadre->salary_scale_id) {
                throw ValidationException::withMessages([
                    'salary_scale_id' => 'The selected rank must use the same salary scale as its cadre.',
                ]);
            }

            $exists = Rank::query()
                ->where('cadre_id', $cadre->id)
                ->where('salary_scale_id', (int) ($validated['salary_scale_id'] ?? $record?->salary_scale_id))
                ->whereRaw('LOWER(name) = ?', [strtolower((string) ($validated['name'] ?? $record?->name))])
                ->where('level', (int) ($validated['level'] ?? $record?->level))
                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => 'A rank with this name, level, cadre, department, and salary scale already exists.',
                ]);
            }

            return;
        }

        if ($config['type'] === 'salary-structure-rates') {
            $scale = SalaryScale::query()->findOrFail($validated['salary_scale_id']);
            $requestedMdaId = $this->resolveManagedMdaId($config, $validated, $record, request()->user());

            if (isset($validated['mda_id']) && (int) $validated['mda_id'] !== (int) $scale->mda_id) {
                throw ValidationException::withMessages([
                    'mda_id' => 'The submitted MDA must match the selected salary scale MDA.',
                ]);
            }

            if ((int) $scale->mda_id !== $requestedMdaId) {
                throw ValidationException::withMessages([
                    'salary_scale_id' => 'The selected salary scale must belong to the same MDA as the salary structure rate.',
                ]);
            }

            if ((int) $validated['level'] < (int) $scale->min_level || (int) $validated['level'] > (int) $scale->max_level) {
                throw ValidationException::withMessages([
                    'level' => "Level must fall between {$scale->min_level} and {$scale->max_level} for the selected scale.",
                ]);
            }

            if ((int) $validated['step'] < (int) $scale->min_step || (int) $validated['step'] > (int) $scale->max_step) {
                throw ValidationException::withMessages([
                    'step' => "Step must fall between {$scale->min_step} and {$scale->max_step} for the selected scale.",
                ]);
            }

            $exists = SalaryStructureRate::query()
                ->where('mda_id', $requestedMdaId)
                ->where('salary_scale_id', $validated['salary_scale_id'])
                ->where('level', $validated['level'])
                ->where('step', $validated['step'])
                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'level' => 'A salary structure rate already exists for this scale, level, and step.',
                ]);
            }

            return;
        }

        if ($config['type'] === 'salary-structure-rate-allowances') {
            $rate = SalaryStructureRate::query()->findOrFail($validated['salary_structure_rate_id']);
            $allowanceType = AllowanceType::query()->findOrFail($validated['allowance_type_id']);
            $requestedMdaId = $this->resolveManagedMdaId($config, $validated, $record, request()->user());

            if (isset($validated['mda_id']) && (int) $validated['mda_id'] !== (int) $rate->mda_id) {
                throw ValidationException::withMessages([
                    'mda_id' => 'The submitted MDA must match the selected salary structure rate MDA.',
                ]);
            }

            if ((int) $rate->mda_id !== $requestedMdaId) {
                throw ValidationException::withMessages([
                    'salary_structure_rate_id' => 'The selected salary structure rate must belong to the same MDA as this allowance mapping.',
                ]);
            }

            if ((int) $allowanceType->mda_id !== $requestedMdaId) {
                throw ValidationException::withMessages([
                    'allowance_type_id' => 'The selected allowance type must belong to the same MDA as this allowance mapping.',
                ]);
            }

            $exists = SalaryStructureRateAllowance::query()
                ->where('mda_id', $requestedMdaId)
                ->where('salary_structure_rate_id', $validated['salary_structure_rate_id'])
                ->where('allowance_type_id', $validated['allowance_type_id'])
                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'allowance_type_id' => 'This allowance type is already attached to the selected salary structure rate.',
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $config, array $validated, ?Model $record = null): array
    {
        return match ($config['type']) {
            'departments', 'stations' => [
                'mda_id' => (int) ($validated['mda_id'] ?? $record?->mda_id ?? request()->user()->mda_id),
                'code' => strtoupper((string) $validated['code']),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
            ],
            'cadres' => [
                'department_id' => (int) $validated['department_id'],
                'salary_scale_id' => (int) $validated['salary_scale_id'],
                'name' => $validated['name'],
                'legacy_department_name' => optional(Department::query()->find($validated['department_id']))->name,
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
            ],
            'ranks' => [
                'cadre_id' => (int) $validated['cadre_id'],
                'salary_scale_id' => (int) $validated['salary_scale_id'],
                'name' => $validated['name'],
                'level' => (int) $validated['level'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
            ],
            'allowance-types', 'qualification-types' => [
                'mda_id' => $this->resolveManagedMdaId($config, $validated, $record, request()->user()),
                'code' => strtoupper((string) $validated['code']),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
            ],
            'salary-scales' => [
                'mda_id' => $this->resolveManagedMdaId($config, $validated, $record, request()->user()),
                'code' => strtoupper((string) $validated['code']),
                'name' => $validated['name'],
                'min_level' => (int) $validated['min_level'],
                'max_level' => (int) $validated['max_level'],
                'min_step' => (int) $validated['min_step'],
                'max_step' => (int) $validated['max_step'],
                'status' => $validated['status'],
            ],
            'salary-structure-rates' => [
                'mda_id' => (int) optional(SalaryScale::query()->find($validated['salary_scale_id']))->mda_id,
                'salary_scale_id' => (int) $validated['salary_scale_id'],
                'level' => (int) $validated['level'],
                'step' => (int) $validated['step'],
                'basic_salary' => $validated['basic_salary'],
                'legacy_gross_salary' => $validated['legacy_gross_salary'] ?? null,
                'status' => $validated['status'],
                'effective_from' => $validated['effective_from'] ?? null,
                'effective_to' => $validated['effective_to'] ?? null,
            ],
            'salary-structure-rate-allowances' => [
                'mda_id' => (int) optional(SalaryStructureRate::query()->find($validated['salary_structure_rate_id']))->mda_id,
                'salary_structure_rate_id' => (int) $validated['salary_structure_rate_id'],
                'allowance_type_id' => (int) $validated['allowance_type_id'],
                'amount' => $validated['amount'],
                'status' => $validated['status'],
            ],
            default => $validated,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadForRecord(array $config, Model $record): array
    {
        return $record->getAttributes();
    }

    protected function serializeRecord(string $type, Model $record): array
    {
        return match ($type) {
            'departments', 'stations' => $record->only(['id', 'mda_id', 'code', 'name', 'description', 'status']),
            'cadres' => $record->load(['department:id,mda_id,code,name', 'salaryScale:id,code,name'])->only(['id', 'department_id', 'salary_scale_id', 'name', 'description', 'status']) + [
                'department' => $record->department?->only(['id', 'mda_id', 'code', 'name']),
                'salary_scale' => $record->salaryScale?->only(['id', 'code', 'name']),
            ],
            'ranks' => $record->load(['cadre.department:id,mda_id,code,name', 'salaryScale:id,code,name'])->only(['id', 'cadre_id', 'salary_scale_id', 'name', 'level', 'description', 'status']) + [
                'cadre' => [
                    'id' => $record->cadre?->id,
                    'name' => $record->cadre?->name,
                    'department' => $record->cadre?->department?->only(['id', 'mda_id', 'code', 'name']),
                ],
                'salary_scale' => $record->salaryScale?->only(['id', 'code', 'name']),
            ],
            'allowance-types', 'qualification-types' => $record->only(['id', 'mda_id', 'code', 'name', 'description', 'status']),
            'salary-scales' => $record->only(['id', 'mda_id', 'code', 'name', 'min_level', 'max_level', 'min_step', 'max_step', 'status']),
            'salary-structure-rates' => $record->load('salaryScale:id,mda_id,code,name')->only(['id', 'mda_id', 'salary_scale_id', 'level', 'step', 'basic_salary', 'legacy_gross_salary', 'status', 'effective_from', 'effective_to']) + [
                'salary_scale' => $record->salaryScale?->only(['id', 'code', 'name']),
            ],
            'salary-structure-rate-allowances' => $record->load(['salaryStructureRate.salaryScale:id,mda_id,code,name', 'allowanceType:id,mda_id,code,name'])->only(['id', 'mda_id', 'salary_structure_rate_id', 'allowance_type_id', 'amount', 'status']) + [
                'salary_structure_rate' => $record->salaryStructureRate
                    ? [
                        'id' => $record->salaryStructureRate->id,
                        'level' => $record->salaryStructureRate->level,
                        'step' => $record->salaryStructureRate->step,
                        'salary_scale' => $record->salaryStructureRate->salaryScale?->only(['id', 'code', 'name']),
                    ]
                    : null,
                'allowance_type' => $record->allowanceType?->only(['id', 'code', 'name']),
            ],
            default => $record->toArray(),
        };
    }

    protected function resolveManagedMdaId(array $config, array $validated, ?Model $record, $user): int
    {
        return match ($config['type']) {
            'salary-structure-rates' => (int) optional(SalaryScale::query()->find($validated['salary_scale_id'] ?? $record?->salary_scale_id))->mda_id,
            'salary-structure-rate-allowances' => (int) optional(SalaryStructureRate::query()->find($validated['salary_structure_rate_id'] ?? $record?->salary_structure_rate_id))->mda_id,
            default => (int) ($validated['mda_id'] ?? $record?->mda_id ?? $user->mda_id ?? 0),
        };
    }
}
