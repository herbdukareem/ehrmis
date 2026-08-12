<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
class UpdateWorkplanRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['title'=>['sometimes','required','string','max:255'],'description'=>['nullable','string']]; } }
