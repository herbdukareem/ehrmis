<?php
namespace App\Http\Requests\Workplan; use Illuminate\Foundation\Http\FormRequest;
class UpdateWorkplanProgressReportRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['reported_expenditure'=>['nullable','numeric','min:0'],'achievement_summary'=>['nullable','string'],'challenges'=>['nullable','string'],'corrective_action'=>['nullable','string'],'next_period_action'=>['nullable','string'],'remarks'=>['nullable','string']];} }
