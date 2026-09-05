<?php

namespace App\Http\Requests;

use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CreateReferralCampaignLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'channel' => ['required', new Enum(ReferralCampaignChannel::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название ссылки.',
            'name.min' => 'Название ссылки должно содержать не менее 2 символов.',
            'name.max' => 'Название ссылки слишком длинное.',
            'channel.required' => 'Выберите канал.',
            'channel.enum' => 'Выберите канал из списка.',
        ];
    }
}
