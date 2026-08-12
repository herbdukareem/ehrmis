<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreWorkplanIndicatorRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['code'=>['required','string','max:100'],'indicator'=>['required','string','max:255'],'unit'=>['nullable','string','max:100'],'baseline_value'=>['nullable','numeric'],'annual_target_value'=>['nullable','numeric'],'target_mode'=>['required',Rule::in(['absolute','increase_from_baseline','decrease_from_baseline','milestone'])],'direction'=>['required',Rule::in(['increase','decrease','milestone'])],'weight'=>['nullable','numeric','gt:0'],'sort_order'=>['nullable','integer','min:0'],'is_required'=>['nullable','boolean']]; } }
