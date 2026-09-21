<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyClientEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:320'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalClientMessages::class)->validationMessages('email_verify');
    }
}
