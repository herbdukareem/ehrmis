<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workplans', function (Blueprint $table): void {
            $table->string('document_classification')->nullable()->after('title');
            $table->string('prepared_by_label')->nullable()->after('prepared_by');
            $table->text('overall_goal')->nullable()->after('description');
            $table->json('strategic_directions')->nullable()->after('overall_goal');
            $table->json('planning_assumptions')->nullable()->after('strategic_directions');
        });

        Schema::table('workplan_objectives', function (Blueprint $table): void {
            $table->string('lead_scope')->nullable()->after('title');
            $table->decimal('planned_cost', 18, 2)->nullable()->after('performance_weight');
        });

        Schema::table('workplan_indicators', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('indicator');
        });
    }

    public function down(): void
    {
        Schema::table('workplan_indicators', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('workplan_objectives', function (Blueprint $table): void {
            $table->dropColumn(['lead_scope', 'planned_cost']);
        });

        Schema::table('workplans', function (Blueprint $table): void {
            $table->dropColumn([
                'document_classification',
                'prepared_by_label',
                'overall_goal',
                'strategic_directions',
                'planning_assumptions',
            ]);
        });
    }
};
