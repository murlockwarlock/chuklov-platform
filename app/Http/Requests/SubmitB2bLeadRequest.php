<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitB2bLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'specialist_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'submission_key' => ['required', 'string', 'max:128'],
        ];
    }
}
