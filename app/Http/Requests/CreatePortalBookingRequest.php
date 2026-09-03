<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalBookingErrorMessages;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreatePortalBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'min:1'],
            'specialist_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'format' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (VisitFormat $format): string => $format->value,
                VisitFormat::cases(),
            ))],
            'party_size' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'consents' => ['sometimes', 'array'],
            'consents.*' => ['required', 'array:legal_document_id,granted'],
            'consents.*.legal_document_id' => ['required', 'integer', 'min:1'],
            'consents.*.granted' => ['required', 'boolean'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'attribution_source' => ['sometimes', 'nullable', 'string', 'max:120', 'in:friend,social,search,partner,other'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalBookingErrorMessages::class)->creationRequestMessages();
    }
}
