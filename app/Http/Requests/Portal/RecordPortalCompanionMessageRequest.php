<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

final class RecordPortalCompanionMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:8000'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{16,128}$/'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'reinspect_recent_images' => ['sometimes', 'boolean'],
        ];
    }
}
