<?php

namespace App\Http\Requests;

use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PortalAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'specialist_id' => ['required', 'integer', 'min:1'],
            'service_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d'],
            'format' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (VisitFormat $format): string => $format->value,
                VisitFormat::cases(),
            ))],
            'display_timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'specialist_id.required' => 'Выберите специалиста.',
            'service_id.required' => 'Выберите услугу.',
            'date_from.date_format' => 'Выберите корректную дату.',
            'date_to.date_format' => 'Выберите корректную дату.',
            'format.in' => 'Выберите формат визита из списка.',
            'display_timezone.max' => 'Не удалось определить часовой пояс.',
        ];
    }
}
