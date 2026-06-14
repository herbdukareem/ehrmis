<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_documents', function (Blueprint $table): void {
            $table->string('compiled_pdf_path')->nullable()->after('notes');
            $table->unsignedBigInteger('compiled_pdf_size')->nullable()->after('compiled_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('staff_documents', function (Blueprint $table): void {
            $table->dropColumn(['compiled_pdf_path', 'compiled_pdf_size']);
        });
    }
};
