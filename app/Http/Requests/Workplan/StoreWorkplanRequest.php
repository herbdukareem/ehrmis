<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
class StoreWorkplanRequest extends FormRequest { public function authorize(): bool { return $this->user()?->can('create-workplans') ?? false; } public function rules(): array { return ['mda_id'=>['required','integer','exists:mdas,id'],'year'=>['required','integer','min:2020','max:2100'],'title'=>['required','string','max:255'],'document_classification'=>['nullable','string','max:255'],'description'=>['nullable','string'],'overall_goal'=>['nullable','string'],'strategic_directions'=>['nullable','array'],'strategic_directions.*'=>['string','max:255'],'planning_assumptions'=>['nullable','array'],'planning_assumptions.*'=>['string'],'prepared_by_label'=>['nullable','string','max:255']]; } }
