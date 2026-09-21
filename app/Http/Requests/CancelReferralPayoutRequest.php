<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelReferralPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'alpha_dash', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalClientMessages::class)->validationMessages('payout_cancel');
    }
}
