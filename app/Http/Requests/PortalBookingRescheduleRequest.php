<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalBookingErrorMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PortalBookingRescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'expected_event_version' => ['required', 'integer', 'min:1'],
            'client_timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'working_location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'location_area' => ['sometimes', 'nullable', 'string', 'max:160'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalBookingErrorMessages::class)->rescheduleRequestMessages();
    }
}
