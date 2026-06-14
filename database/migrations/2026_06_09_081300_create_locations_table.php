<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('state');
            $table->string('lga')->index();
            $table->string('ward')->nullable();
            $table->string('town');
            $table->boolean('is_urban_center')->default(false)->index();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['state', 'lga', 'ward', 'town'], 'locations_state_lga_ward_town_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
