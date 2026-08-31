<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
class UpdateWorkplanRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['title'=>['sometimes','required','string','max:255'],'document_classification'=>['sometimes','nullable','string','max:255'],'description'=>['sometimes','nullable','string'],'overall_goal'=>['sometimes','nullable','string'],'strategic_directions'=>['sometimes','nullable','array'],'strategic_directions.*'=>['string','max:255'],'planning_assumptions'=>['sometimes','nullable','array'],'planning_assumptions.*'=>['string'],'prepared_by_label'=>['sometimes','nullable','string','max:255']]; } }
