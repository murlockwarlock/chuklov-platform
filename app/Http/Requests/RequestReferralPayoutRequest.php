<?php

namespace App\Http\Requests;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestReferralPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:30', 'regex:/^(?:0|[1-9][0-9]{0,18})(?:\.[0-9]{1,2})?$/'],
            'currency' => ['required', 'string', 'in:'.implode(',', array_map(static fn (CurrencyCode $currency): string => $currency->value, CurrencyCode::cases()))],
            'idempotency_key' => ['required', 'string', 'alpha_dash', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PortalClientMessages::class)->validationMessages('payout');
    }
}
