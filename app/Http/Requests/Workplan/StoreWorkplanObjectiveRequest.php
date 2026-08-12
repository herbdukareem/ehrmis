<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
class StoreWorkplanObjectiveRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['department_id'=>['nullable','integer','exists:departments,id'],'code'=>['required','string','max:100'],'title'=>['required','string','max:255'],'description'=>['nullable','string'],'priority'=>['nullable','string','max:50'],'performance_weight'=>['nullable','numeric','gt:0'],'sort_order'=>['nullable','integer','min:0']]; } }
