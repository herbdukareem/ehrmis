<?php

use App\Domain\Staff\Services\QualificationCatalogSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qualification_types') || ! Schema::hasTable('qualification_scale_ceilings')) {
            return;
        }

        app(QualificationCatalogSyncService::class)->syncAll(seedSalaryScales: true);
    }

    public function down(): void
    {
        // The unified catalog is reference data used by staff records, movement sheets, and promotion workflows.
        // Keep it in place on rollback to avoid orphaning qualification history.
    }
};
