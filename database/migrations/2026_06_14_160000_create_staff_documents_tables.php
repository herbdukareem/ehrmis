<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->string('passport_path')->nullable()->after('date_of_birth');
            $table->string('passport_mime_type')->nullable()->after('passport_path');
        });

        Schema::create('staff_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('title');
            $table->string('document_type')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('staff_id');
        });

        Schema::create('staff_document_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_document_id')->constrained('staff_documents')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();

            $table->unique(['staff_document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_document_pages');
        Schema::dropIfExists('staff_documents');

        Schema::table('staff', function (Blueprint $table): void {
            $table->dropColumn(['passport_path', 'passport_mime_type']);
        });
    }
};
