<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use Illuminate\Foundation\Http\FormRequest;

final class CreatePatientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'creation_key' => ['required', 'uuid'], 'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'], 'middle_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', 'in:MALE,FEMALE'], 'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'relationship' => ['required', 'in:SPOUSE,CHILD,PARENT'],
            'return_to' => ['sometimes', 'in:family,booking'], 'service_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'specialist_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'format' => ['sometimes', 'in:office,home,online'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'display_timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'working_location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'location_area' => ['sometimes', 'nullable', 'string', 'max:160'],
            'selected_start_at' => ['sometimes', 'nullable', 'date'], 'booking_step' => ['sometimes', 'in:time,confirmation'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalClientMessages::class)->validationMessages('patient_profile');
    }
}
