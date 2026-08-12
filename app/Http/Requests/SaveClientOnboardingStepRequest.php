<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveClientOnboardingStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email', 'max:320'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'language' => ['sometimes', 'nullable', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'lead_source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'referral_code' => ['sometimes', 'nullable', 'string', 'max:160'],
            'confirmed_fields' => ['sometimes', 'array'],
            'confirmed_fields.*' => [
                'string',
                'in:full_name,email,phone,language,timezone,lead_source,referral_code',
            ],
            'consents' => ['sometimes', 'array'],
            'consents.*' => ['required', 'array:legal_document_id,granted'],
            'consents.*.legal_document_id' => ['required', 'integer', 'min:1'],
            'consents.*.granted' => ['required', 'boolean'],
        ];
    }
}
