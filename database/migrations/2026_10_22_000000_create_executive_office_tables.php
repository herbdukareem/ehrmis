<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->unique()->constrained('mdas');
            $table->string('title');
            $table->string('reference_prefix', 40);
            $table->unsignedBigInteger('next_reference')->default(1);
            $table->string('timezone')->default('Africa/Lagos');
            $table->boolean('pa_confirms_routine')->default(true);
            $table->unsignedInteger('reminder_minutes')->default(60);
            $table->string('workday_start', 5)->default('08:00');
            $table->string('workday_end', 5)->default('17:00');
            $table->json('workdays')->nullable();
            $table->timestamps();
        });
        Schema::create('executive_office_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 30);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamps();
            $table->index(['office_id', 'user_id', 'role'], 'eo_membership_lookup');
        });
        Schema::create('executive_office_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->string('type', 30);
            $table->string('reference', 100);
            $table->string('subject');
            $table->longText('body')->nullable();
            $table->string('status', 40);
            $table->string('priority', 20)->default('normal');
            $table->string('confidentiality', 30)->default('standard');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->json('details')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('approved_version')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['office_id', 'reference']);
            $table->index(['mda_id', 'office_id', 'type', 'status'], 'eo_record_register');
            $table->index(['office_id', 'starts_at', 'ends_at'], 'eo_diary_range');
        });
        Schema::create('executive_office_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('record_id')->nullable()->constrained('executive_office_records');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action', 60);
            $table->string('visibility', 30)->default('office');
            $table->longText('comment')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
        Schema::create('executive_office_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('record_id')->constrained('executive_office_records');
            $table->foreignId('author_id')->constrained('users');
            $table->string('visibility', 30);
            $table->longText('body');
            $table->timestamps();
        });
        Schema::create('executive_office_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('record_id')->constrained('executive_office_records');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('name');
            $table->string('path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('record_version');
            $table->string('visibility', 30)->default('office');
            $table->timestamps();
        });
        Schema::create('executive_office_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('source_id')->constrained('executive_office_records');
            $table->foreignId('target_id')->constrained('executive_office_records');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['source_id', 'target_id']);
        });
        Schema::create('executive_office_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('granted_by')->constrained('users');
            $table->json('abilities');
            $table->boolean('executive_only_access')->default(false);
            $table->text('reason');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('executive_office_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas');
            $table->foreignId('office_id')->constrained('executive_offices');
            $table->foreignId('record_id')->constrained('executive_office_records');
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 30);
            $table->string('dedupe_key', 64)->unique();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'delegations', 'links', 'attachments', 'notes', 'events', 'records', 'memberships'] as $table) {
            Schema::dropIfExists('executive_office_'.$table);
        }
        Schema::dropIfExists('executive_offices');
    }
};
