<?php
namespace App\Http\Requests\Workplan; use Illuminate\Foundation\Http\FormRequest;
class StoreWorkplanProgressReportRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['period'=>['required','in:q1,q2,q3,q4,annual']];} }
