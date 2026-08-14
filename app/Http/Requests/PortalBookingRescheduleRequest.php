<?php

namespace App\Http\Requests;

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
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_at.required' => 'Выберите новое время.',
            'starts_at.date' => 'Выберите корректные дату и время.',
            'expected_event_version.required' => 'Обновите страницу и выберите время ещё раз.',
            'expected_event_version.integer' => 'Обновите страницу и выберите время ещё раз.',
            'client_timezone.max' => 'Не удалось сохранить часовой пояс.',
            'reason.max' => 'Комментарий слишком длинный.',
        ];
    }
}
