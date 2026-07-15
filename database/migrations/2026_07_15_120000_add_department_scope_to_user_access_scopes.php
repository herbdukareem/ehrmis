<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_access_scopes', function (Blueprint $table) {
            $table->dropUnique('user_access_scope_unique');
            $table->foreignId('department_id')->nullable()->after('mda_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'scope_type', 'state_code', 'mda_id', 'department_id'], 'user_access_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_access_scopes', function (Blueprint $table) {
            $table->dropUnique('user_access_scope_unique');
            $table->dropConstrainedForeignId('department_id');
            $table->unique(['user_id', 'scope_type', 'state_code', 'mda_id'], 'user_access_scope_unique');
        });
    }
};
