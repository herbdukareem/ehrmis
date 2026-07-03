<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;

class WorkflowReportSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
        ];
    }
}
