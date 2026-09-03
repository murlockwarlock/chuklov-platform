<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecordPortalClientConsentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'consents' => ['present', 'array'],
            'consents.*' => ['required', 'array:legal_document_id,granted'],
            'consents.*.legal_document_id' => ['required', 'integer', 'min:1'],
            'consents.*.granted' => ['required', 'boolean'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'consents.array' => 'Проверьте согласия с документами.',
            'consents.present' => 'Проверьте согласия с документами.',
            'consents.*.legal_document_id.required' => 'Выберите документ.',
            'consents.*.granted.required' => 'Подтвердите согласие.',
        ];
    }
}
