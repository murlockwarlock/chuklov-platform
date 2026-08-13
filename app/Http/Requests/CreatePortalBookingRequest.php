<?php

namespace App\Http\Requests;

use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
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
            'client_timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'meeting_link_mode' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_map(
                static fn (MeetingLinkMode $mode): string => $mode->value,
                MeetingLinkMode::cases(),
            ))],
            'party_size' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128'],
        ];
    }
}
