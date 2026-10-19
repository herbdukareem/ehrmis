<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_alert_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('alert_key', 64);
            $table->timestamp('read_at');
            $table->unique(['mda_id', 'user_id', 'alert_key'], 'procurement_alert_read_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_alert_reads');
    }
};
