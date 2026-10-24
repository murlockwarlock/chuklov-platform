<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePatientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', 'in:MALE,FEMALE'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'relationship' => ['required', 'in:SPOUSE,CHILD,PARENT'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalClientMessages::class)->validationMessages('patient_profile');
    }
}
