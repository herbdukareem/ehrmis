<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_staff_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source_database');
            $table->string('source_table');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_staff_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_staff_import_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_staff_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_master_staff_id')->nullable()->index();
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->string('dedupe_key')->nullable()->index();
            $table->string('status', 30)->default('staged')->index();
            $table->foreignId('matched_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('published_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('legacy_staff_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_staff_import_batches')->cascadeOnDelete();
            $table->foreignId('row_id')->nullable()->constrained('legacy_staff_import_rows')->nullOnDelete();
            $table->string('field')->nullable();
            $table->string('error_code', 100)->index();
            $table->text('message');
            $table->string('severity', 20)->default('warning')->index();
            $table->timestamps();
        });

        Schema::create('legacy_staff_import_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_staff_import_batches')->cascadeOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->json('summary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_staff_import_publications');
        Schema::dropIfExists('legacy_staff_import_errors');
        Schema::dropIfExists('legacy_staff_import_rows');
        Schema::dropIfExists('legacy_staff_import_batches');
    }
};
