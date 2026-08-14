<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PortalTimezonePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['timezone' => ['required', 'string', 'max:64']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'timezone.required' => 'Выберите часовой пояс.',
            'timezone.max' => 'Не удалось сохранить часовой пояс.',
        ];
    }
}
