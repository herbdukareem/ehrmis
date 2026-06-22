<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('scope')->default(Role::SCOPE_GLOBAL)->after('guard_name');
            $table->foreignId('mda_id')->nullable()->after('scope')->constrained('mdas')->nullOnDelete();
            $table->index(['scope', 'mda_id']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mda_id');
            $table->dropIndex(['scope', 'mda_id']);
            $table->dropColumn('scope');
        });
    }
};
