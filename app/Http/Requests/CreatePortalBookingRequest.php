<?php

namespace App\Http\Requests;

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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service_id.required' => 'Выберите услугу.',
            'service_id.integer' => 'Выберите услугу из списка.',
            'specialist_id.required' => 'Выберите специалиста.',
            'specialist_id.integer' => 'Выберите специалиста из списка.',
            'starts_at.required' => 'Выберите дату и время.',
            'starts_at.date' => 'Выберите корректные дату и время.',
            'format.required' => 'Выберите формат визита.',
            'format.in' => 'Выберите формат визита из списка.',
            'party_size.integer' => 'Укажите количество человек.',
            'party_size.min' => 'Количество человек должно быть не меньше одного.',
            'party_size.max' => 'Количество человек не может быть больше 20.',
            'location.required' => 'Укажите адрес выезда.',
            'location.max' => 'Адрес слишком длинный.',
        ];
    }
}
