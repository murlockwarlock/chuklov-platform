<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientB2bAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'b2b_specialist_answer' => ['required', 'string', 'in:yes,no'],
            'return_to' => ['nullable', 'string', 'in:profile,b2b'],
        ];
    }
}
