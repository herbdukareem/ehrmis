<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Support\AllowanceTypeCatalog;
use Illuminate\Support\Collection;

class AllowanceTypeProvisioningService
{
    /**
     * @param  array<int, string>  $codes
     * @return array{types: Collection<int, AllowanceType>, created: int, updated: int}
     */
    public function ensureForMda(int $mdaId, array $codes = []): array
    {
        return $this->ensureGlobal($codes);
    }

    /**
     * @param  array<int, string>  $codes
     * @return array{types: Collection<int, AllowanceType>, created: int, updated: int}
     */
    public function ensureGlobal(array $codes = []): array
    {
        $normalizedCodes = collect($codes)
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => strtolower(trim($code)))
            ->unique()
            ->values();

        if ($normalizedCodes->isEmpty()) {
            $normalizedCodes = collect(AllowanceTypeCatalog::definitions())->pluck('code')->values();
        }

        $definitions = $normalizedCodes
            ->mapWithKeys(fn (string $code): array => [$code => AllowanceTypeCatalog::definitionFor($code)]);

        $existing = AllowanceType::query()
            ->whereIn('code', $normalizedCodes->all())
            ->get()
            ->keyBy('code');

        $created = 0;
        $updated = 0;

        foreach ($definitions as $code => $definition) {
            /** @var AllowanceType $allowanceType */
            $allowanceType = $existing->get($code) ?? new AllowanceType();
            $wasExisting = $allowanceType->exists;

            $allowanceType->fill([
                'code' => $definition['code'],
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => 'active',
            ]);
            $allowanceType->save();

            $existing->put($code, $allowanceType);
            $wasExisting ? $updated++ : $created++;
        }

        return [
            'types' => $existing->sortBy('code')->values(),
            'created' => $created,
            'updated' => $updated,
        ];
    }
}
