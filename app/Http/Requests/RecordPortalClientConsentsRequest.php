<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
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
        return app(PortalClientMessages::class)->validationMessages('consents');
    }
}
