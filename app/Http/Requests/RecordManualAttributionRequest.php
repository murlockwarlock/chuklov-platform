<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecordManualAttributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_detail.string' => 'Укажите имя, Telegram, телефон или другое уточнение текстом.',
            'source_detail.max' => 'Укажите не более 500 символов.',
        ];
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:120', 'in:friend,social,search,partner,other'],
            'source_detail' => ['nullable', 'string', 'max:500'],
        ];
    }
}
