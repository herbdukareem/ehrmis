<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->string('lga', 120)->nullable()->index();
            $table->boolean('is_rural')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropIndex(['lga']);
            $table->dropColumn(['lga', 'is_rural']);
        });
    }
};
