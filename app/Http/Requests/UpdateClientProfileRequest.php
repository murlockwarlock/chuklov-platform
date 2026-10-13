<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientProfileRequest extends FormRequest
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
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['required_with:first_name', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['required_with:first_name', 'in:MALE,FEMALE'],
            'birth_date' => ['required_with:first_name', 'date_format:Y-m-d', 'before_or_equal:today'],
            'country' => ['required_with:first_name', 'string', 'max:100'],
            'city' => ['required_with:first_name', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:320'],
            'phone' => ['required_with:first_name', 'nullable', 'string', 'max:32'],
            'timezone' => ['sometimes', 'required', 'timezone', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalClientMessages::class)->validationMessages('profile');
    }
}
