<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\Module\Models\Module;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Models\ReportTemplateIndicator;
use App\Domain\ServiceReporting\Models\ReportTemplateSection;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportTemplateService
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function create(array $data, User $actor): ReportTemplate
    {
        return DB::transaction(function () use ($data, $actor): ReportTemplate {
            $template = ReportTemplate::query()->create([
                ...Arr::only($data, [
                    'owner_mda_id',
                    'name',
                    'code',
                    'description',
                    'frequency',
                    'status',
                    'requires_approval',
                    'submission_deadline_day',
                    'allow_late_submission',
                ]),
                'module_id' => Module::query()->where('code', 'service_reporting')->value('id'),
                'module_code' => 'service_reporting',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncStructure($template, $data['sections'] ?? []);
            $this->audit('service_reporting.template.created', $template, [], $template->toArray(), $actor);

            return $template->fresh(['sections.indicators.dimensions', 'sections.indicators.options', 'ownerMda']);
        });
    }

    public function update(ReportTemplate $template, array $data, User $actor): ReportTemplate
    {
        return DB::transaction(function () use ($template, $data, $actor): ReportTemplate {
            $before = $template->toArray();
            $template->fill([
                ...Arr::only($data, [
                    'owner_mda_id',
                    'name',
                    'code',
                    'description',
                    'frequency',
                    'status',
                    'requires_approval',
                    'submission_deadline_day',
                    'allow_late_submission',
                ]),
                'updated_by' => $actor->id,
            ])->save();

            if (array_key_exists('sections', $data)) {
                $this->syncStructure($template, $data['sections'] ?? []);
            }

            $this->audit('service_reporting.template.updated', $template, $before, $template->fresh()->toArray(), $actor);

            return $template->fresh(['sections.indicators.dimensions', 'sections.indicators.options', 'ownerMda']);
        });
    }

    public function activate(ReportTemplate $template, User $actor): ReportTemplate
    {
        $this->assertCanActivate($template);

        return $this->setStatus($template, 'active', $actor);
    }

    public function deactivate(ReportTemplate $template, User $actor): ReportTemplate
    {
        return $this->setStatus($template, 'inactive', $actor);
    }

    public function syncStructure(ReportTemplate $template, array $sections): void
    {
        foreach ($sections as $sectionIndex => $sectionData) {
            $section = $template->sections()->updateOrCreate(
                ['code' => $sectionData['code']],
                [
                    'title' => $sectionData['title'],
                    'description' => $sectionData['description'] ?? null,
                    'sort_order' => $sectionData['sort_order'] ?? (($sectionIndex + 1) * 10),
                ],
            );

            foreach ($sectionData['indicators'] ?? [] as $indicatorIndex => $indicatorData) {
                $indicator = $section->indicators()->updateOrCreate(
                    ['code' => $indicatorData['code']],
                    [
                        'label' => $indicatorData['label'],
                        'description' => $indicatorData['description'] ?? null,
                        'placeholder' => $indicatorData['placeholder'] ?? null,
                        'help_text' => $indicatorData['help_text'] ?? null,
                        'value_type' => $indicatorData['value_type'] ?? 'integer',
                        'field_type' => $indicatorData['field_type'] ?? $this->fieldTypeForValueType($indicatorData['value_type'] ?? 'integer'),
                        'unit' => $indicatorData['unit'] ?? null,
                        'default_value' => $indicatorData['default_value'] ?? null,
                        'is_required' => $indicatorData['is_required'] ?? false,
                        'is_computed' => $indicatorData['is_computed'] ?? false,
                        'is_read_only' => $indicatorData['is_read_only'] ?? false,
                        'contributes_to_score' => $indicatorData['contributes_to_score'] ?? false,
                        'compute_formula' => $indicatorData['compute_formula'] ?? null,
                        'validation_rules' => $indicatorData['validation_rules'] ?? null,
                        'display_rules' => $indicatorData['display_rules'] ?? null,
                        'required_rules' => $indicatorData['required_rules'] ?? null,
                        'sort_order' => $indicatorData['sort_order'] ?? (($indicatorIndex + 1) * 10),
                        'status' => $indicatorData['status'] ?? 'active',
                    ],
                );

                if (array_key_exists('dimensions', $indicatorData)) {
                    $indicator->dimensions()->delete();
                    foreach ($indicatorData['dimensions'] ?? [] as $dimensionIndex => $dimensionData) {
                        $indicator->dimensions()->create([
                            'dimension_key' => $dimensionData['dimension_key'],
                            'dimension_label' => $dimensionData['dimension_label'],
                            'dimension_values' => $dimensionData['dimension_values'],
                            'is_required' => $dimensionData['is_required'] ?? false,
                            'total_strategy' => $dimensionData['total_strategy'] ?? null,
                            'sort_order' => $dimensionData['sort_order'] ?? (($dimensionIndex + 1) * 10),
                        ]);
                    }
                }

                if (array_key_exists('options', $indicatorData)) {
                    $this->syncOptions($indicator, $indicatorData['options'] ?? []);
                }
            }
        }
    }

    public function addSection(ReportTemplate $template, array $data): ReportTemplateSection
    {
        return $template->sections()->create($data)->fresh(['indicators.dimensions', 'indicators.options']);
    }

    public function updateSection(ReportTemplateSection $section, array $data): ReportTemplateSection
    {
        $section->fill($data)->save();

        return $section->fresh(['indicators.dimensions', 'indicators.options']);
    }

    public function addIndicator(ReportTemplateSection $section, array $data): ReportTemplateIndicator
    {
        $indicator = $section->indicators()->create([
            ...Arr::except($data, ['dimensions', 'options']),
            'field_type' => $data['field_type'] ?? $this->fieldTypeForValueType($data['value_type'] ?? 'integer'),
        ]);

        foreach ($data['dimensions'] ?? [] as $dimension) {
            $indicator->dimensions()->create($dimension);
        }

        $this->syncOptions($indicator, $data['options'] ?? []);

        return $indicator->fresh(['dimensions', 'options']);
    }

    public function updateIndicator(ReportTemplateIndicator $indicator, array $data): ReportTemplateIndicator
    {
        $indicator->fill(Arr::except($data, ['dimensions', 'options']))->save();

        if (array_key_exists('dimensions', $data)) {
            $indicator->dimensions()->delete();
            foreach ($data['dimensions'] ?? [] as $dimension) {
                $indicator->dimensions()->create($dimension);
            }
        }

        if (array_key_exists('options', $data)) {
            $this->syncOptions($indicator, $data['options'] ?? []);
        }

        return $indicator->fresh(['dimensions', 'options']);
    }

    protected function syncOptions(ReportTemplateIndicator $indicator, array $options): void
    {
        $indicator->options()->delete();

        foreach ($options as $optionIndex => $optionData) {
            $indicator->options()->create([
                'label' => $optionData['label'],
                'value' => $optionData['value'],
                'score' => $optionData['score'] ?? null,
                'requires_review' => $optionData['requires_review'] ?? false,
                'sort_order' => $optionData['sort_order'] ?? (($optionIndex + 1) * 10),
            ]);
        }
    }

    protected function fieldTypeForValueType(string $valueType): string
    {
        return match ($valueType) {
            'integer' => 'whole_number',
            'decimal' => 'number',
            'percentage' => 'percentage',
            'boolean' => 'boolean',
            default => 'text',
        };
    }

    protected function assertCanActivate(ReportTemplate $template): void
    {
        $template->loadMissing('sections.indicators');

        if ($template->sections->isEmpty() || $template->sections->flatMap->indicators->isEmpty()) {
            throw ValidationException::withMessages([
                'template' => 'A template requires at least one section and one indicator before activation.',
            ]);
        }
    }

    protected function setStatus(ReportTemplate $template, string $status, User $actor): ReportTemplate
    {
        $before = $template->toArray();
        $template->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
        $this->audit("service_reporting.template.{$status}", $template, $before, $template->fresh()->toArray(), $actor);

        return $template->fresh(['sections.indicators.dimensions', 'sections.indicators.options', 'ownerMda']);
    }

    protected function audit(string $event, ReportTemplate $template, array $before, array $after, User $actor): void
    {
        $this->auditLogService->log($event, $template, $before, $after, [
            'source' => 'service_reporting',
            'template_id' => $template->id,
            'template_code' => $template->code,
            'mda_id' => $template->owner_mda_id,
            'actor_user_id' => $actor->id,
        ]);
    }
}
