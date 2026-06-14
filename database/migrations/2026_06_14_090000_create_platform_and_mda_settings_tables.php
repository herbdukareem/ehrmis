<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('state_code', 20)->default('NG-NI')->unique();
            $table->string('state_name')->default('Niger State');
            $table->string('platform_name')->default('eHRMIS');
            $table->string('platform_acronym', 50)->default('eHRMIS');
            $table->string('default_domain')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->boolean('allow_platform_login')->default(true);
            $table->timestamps();
        });

        Schema::create('mda_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('acronym', 50)->nullable();
            $table->string('domain')->nullable()->unique();
            $table->string('logo_path')->nullable();
            $table->longText('vision_html')->nullable();
            $table->longText('mission_html')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('head_rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('head_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('head_title')->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamps();
        });

        Schema::create('user_access_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20)->index();
            $table->string('state_code', 20)->nullable()->index();
            $table->foreignId('mda_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'scope_type', 'state_code', 'mda_id'], 'user_access_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_scopes');
        Schema::dropIfExists('mda_settings');
        Schema::dropIfExists('platform_settings');
    }
};
