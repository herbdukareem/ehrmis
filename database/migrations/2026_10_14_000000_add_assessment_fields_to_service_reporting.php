<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_template_indicators', function (Blueprint $table): void {
            if (Schema::hasColumn('report_template_indicators', 'field_type')) {
                return;
            }

            $table->string('field_type', 30)->default('whole_number')->after('value_type');
            $table->string('placeholder')->nullable()->after('description');
            $table->text('help_text')->nullable()->after('placeholder');
            $table->text('default_value')->nullable()->after('unit');
            $table->boolean('is_read_only')->default(false)->after('is_computed');
            $table->boolean('contributes_to_score')->default(false)->after('is_read_only');
            $table->json('display_rules')->nullable()->after('validation_rules');
            $table->json('required_rules')->nullable()->after('display_rules');
        });

        DB::table('report_template_indicators')
            ->where('value_type', 'text')
            ->update(['field_type' => 'text']);
        DB::table('report_template_indicators')
            ->where('value_type', 'decimal')
            ->update(['field_type' => 'number']);
        DB::table('report_template_indicators')
            ->where('value_type', 'percentage')
            ->update(['field_type' => 'percentage']);
        DB::table('report_template_indicators')
            ->where('value_type', 'boolean')
            ->update(['field_type' => 'boolean']);

        if (! Schema::hasTable('report_template_indicator_options')) {
            Schema::create('report_template_indicator_options', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_template_indicator_id');
                $table->string('label');
                $table->string('value');
                $table->decimal('score', 10, 2)->nullable();
                $table->boolean('requires_review')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('report_template_indicator_id', 'rtio_indicator_fk')
                    ->references('id')
                    ->on('report_template_indicators')
                    ->cascadeOnDelete();
                $table->unique(['report_template_indicator_id', 'value'], 'report_indicator_option_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template_indicator_options');

        if (Schema::hasColumn('report_template_indicators', 'field_type')) {
            Schema::table('report_template_indicators', function (Blueprint $table): void {
                $table->dropColumn([
                    'field_type',
                    'placeholder',
                    'help_text',
                    'default_value',
                    'is_read_only',
                    'contributes_to_score',
                    'display_rules',
                    'required_rules',
                ]);
            });
        }
    }
};
