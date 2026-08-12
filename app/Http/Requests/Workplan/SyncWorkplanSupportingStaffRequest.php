<?php
namespace App\Http\Requests\Workplan;
use Illuminate\Foundation\Http\FormRequest;
class SyncWorkplanSupportingStaffRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['staff_ids'=>['present','array'],'staff_ids.*'=>['integer','distinct','exists:staff,id']]; } }
