<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $gateway_transaction_id
 * @property string $provider_event_id
 * @property string $provider_reference
 * @property ProviderVerificationStatus $verification_status
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property Carbon|null $processed_at
 */
#[Fillable([])]
class PaymentGatewayEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PaymentGatewayTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayTransaction::class, 'gateway_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'verification_status' => ProviderVerificationStatus::class,
            'currency' => CurrencyCode::class,
            'amount_minor' => 'integer',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
