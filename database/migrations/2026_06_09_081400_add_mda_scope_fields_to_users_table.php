<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('mda_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('user_type', 30)->default('report_viewer')->after('email');
            $table->string('status', 20)->default('active')->after('user_type')->index();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->index(['mda_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['mda_id', 'user_type']);
            $table->dropConstrainedForeignId('mda_id');
            $table->dropColumn(['user_type', 'status', 'last_login_at', 'last_login_ip']);
        });
    }
};
