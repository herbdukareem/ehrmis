<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class SyncWorkplanIndicatorTargetsRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['targets'=>['present','array'],'targets.*.period'=>['required',Rule::in(['q1','q2','q3','q4','annual'])],'targets.*.target_value'=>['nullable','numeric']]; } }
