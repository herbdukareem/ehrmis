<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mda_settings', function (Blueprint $table): void {
            $table->string('posting_reference_prefix')->nullable()->after('signature_path');
            $table->string('posting_reference_suffix')->nullable()->after('posting_reference_prefix');
        });

        Schema::create('staff_posting_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('posting_request_id')->constrained('staff_posting_requests')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->json('staff_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['posting_request_id', 'staff_id'], 'posting_request_staff_unique');
        });

        Schema::table('staff_posting_letters', function (Blueprint $table): void {
            $table->string('official_reference')->nullable()->unique()->after('letter_number');
            $table->unsignedInteger('reference_sequence')->nullable()->after('official_reference');
            $table->string('subject_line')->nullable()->after('reference_sequence');
            $table->string('recipient_name')->nullable()->after('subject_line');
            $table->string('recipient_organisation')->nullable()->after('recipient_name');
            $table->string('recipient_location')->nullable()->after('recipient_organisation');
            $table->string('attention_line')->nullable()->after('recipient_location');
            $table->string('signatory_name')->nullable()->after('attention_line');
            $table->string('signatory_title')->nullable()->after('signatory_name');
            $table->string('signatory_for_line')->nullable()->after('signatory_title');
        });
    }

    public function down(): void
    {
        Schema::table('staff_posting_letters', function (Blueprint $table): void {
            $table->dropUnique(['official_reference']);
            $table->dropColumn([
                'official_reference',
                'reference_sequence',
                'subject_line',
                'recipient_name',
                'recipient_organisation',
                'recipient_location',
                'attention_line',
                'signatory_name',
                'signatory_title',
                'signatory_for_line',
            ]);
        });

        Schema::dropIfExists('staff_posting_request_items');

        Schema::table('mda_settings', function (Blueprint $table): void {
            $table->dropColumn(['posting_reference_prefix', 'posting_reference_suffix']);
        });
    }
};
