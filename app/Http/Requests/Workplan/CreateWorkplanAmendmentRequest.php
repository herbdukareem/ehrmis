<?php

namespace App\Http\Requests\Workplan;

use Illuminate\Foundation\Http\FormRequest;

class CreateWorkplanAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amendment_reason' => ['required', 'string', 'max:1000', 'not_regex:/^\\s*$/'],
        ];
    }
}
