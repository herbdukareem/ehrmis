<?php
namespace App\Http\Requests\Workplan; use Illuminate\Foundation\Http\FormRequest;
class SyncWorkplanIndicatorProgressRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['indicators'=>['required','array'],'indicators.*.workplan_indicator_id'=>['required','integer'],'indicators.*.actual_value'=>['nullable','numeric'],'indicators.*.verification_note'=>['nullable','string']];} }
