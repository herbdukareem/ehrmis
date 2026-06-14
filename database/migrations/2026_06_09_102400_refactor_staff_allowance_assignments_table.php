<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_allowance_assignments') || ! Schema::hasColumn('staff_allowance_assignments', 'allowance_code')) {
            return;
        }

        $legacyRows = DB::table('staff_allowance_assignments')
            ->select('staff_id', 'allowance_code', 'is_eligible', 'source', 'created_at', 'updated_at')
            ->get()
            ->map(fn (object $row) => (array) $row);

        Schema::table('staff_allowance_assignments', function (Blueprint $table): void {
            $table->index('staff_id', 'staff_allowance_staff_id_idx');
            $table->dropUnique('staff_allowance_code_unique');
            $table->dropColumn(['allowance_code', 'legacy_amount']);
        });

        Schema::table('staff_allowance_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('staff_allowance_assignments', 'allowance_type_id')) {
                $table->foreignId('allowance_type_id')->nullable()->after('staff_id')->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasColumn('staff_allowance_assignments', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('source');
            }

            if (! Schema::hasColumn('staff_allowance_assignments', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }
        });

        if ($legacyRows->isNotEmpty()) {
            $codeMap = [
                'shift' => 'shift',
                'hazard' => 'hazard',
                'teaching' => 'teaching',
                'specialist' => 'specialty',
                'rural' => 'rural',
                'domestic' => 'domestic',
            ];

            $typeIds = DB::table('allowance_types')
                ->whereIn('code', array_values($codeMap))
                ->pluck('id', 'code');

            foreach ($legacyRows as $row) {
                $mappedCode = $codeMap[$row['allowance_code']] ?? null;

                if (! $mappedCode || ! isset($typeIds[$mappedCode])) {
                    continue;
                }

                DB::table('staff_allowance_assignments')->updateOrInsert(
                    [
                        'staff_id' => $row['staff_id'],
                        'allowance_type_id' => $typeIds[$mappedCode],
                        'source' => $row['source'] ?: 'legacy_import',
                    ],
                    [
                        'is_eligible' => (bool) $row['is_eligible'],
                        'effective_from' => null,
                        'effective_to' => null,
                        'created_at' => $row['created_at'] ?? now(),
                        'updated_at' => $row['updated_at'] ?? now(),
                    ],
                );
            }
        }

        DB::table('staff_allowance_assignments')
            ->whereNull('allowance_type_id')
            ->delete();

        Schema::table('staff_allowance_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('allowance_type_id')->nullable(false)->change();
            $table->index(['staff_id', 'allowance_type_id'], 'staff_allowance_staff_type_idx');
            $table->unique(['staff_id', 'allowance_type_id', 'source'], 'staff_allowance_staff_type_source_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('staff_allowance_assignments')) {
            return;
        }

        Schema::table('staff_allowance_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('staff_allowance_assignments', 'allowance_type_id')) {
                $table->dropUnique('staff_allowance_staff_type_source_unique');
                $table->dropIndex('staff_allowance_staff_type_idx');
                $table->dropConstrainedForeignId('allowance_type_id');
            }

            if (Schema::hasColumn('staff_allowance_assignments', 'effective_from')) {
                $table->dropColumn(['effective_from', 'effective_to']);
            }

            if (! Schema::hasColumn('staff_allowance_assignments', 'allowance_code')) {
                $table->string('allowance_code', 30)->nullable();
                $table->decimal('legacy_amount', 15, 2)->nullable();
            }
        });

        Schema::table('staff_allowance_assignments', function (Blueprint $table): void {
            $table->index('allowance_code');
            $table->unique(['staff_id', 'allowance_code'], 'staff_allowance_code_unique');
        });
    }
};
