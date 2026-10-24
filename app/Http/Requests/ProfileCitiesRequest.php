<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProfileCitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['country' => ['required', 'string', 'max:100'], 'query' => ['nullable', 'string', 'max:100']];
    }
}
