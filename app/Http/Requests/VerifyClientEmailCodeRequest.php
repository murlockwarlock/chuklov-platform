<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyClientEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:320'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Введите email.',
            'email.email' => 'Введите корректный email.',
            'code.required' => 'Введите код из письма.',
            'code.digits' => 'Код должен содержать 6 цифр.',
        ];
    }
}
