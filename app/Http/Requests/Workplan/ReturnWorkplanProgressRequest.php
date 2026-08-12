<?php
namespace App\Http\Requests\Workplan; use Illuminate\Foundation\Http\FormRequest;
class ReturnWorkplanProgressRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['return_reason'=>['required','string','max:2000']];} }
