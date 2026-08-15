<?php

namespace App\Modules\Finance\Domain\Models;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $obligation_id
 * @property string $gateway
 * @property string $provider_reference
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property int $settlement_amount_minor
 * @property CurrencyCode $settlement_currency
 * @property PaymentGatewayStatus $status
 * @property int|null $ledger_entry_id
 * @property Carbon $initiated_at
 * @property Carbon|null $settled_at
 */
#[Fillable([])]
class PaymentGatewayTransaction extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<FinancialObligation, $this> */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(FinancialObligation::class, 'obligation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<FinancialLedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialLedgerEntry::class, 'ledger_entry_id');
    }

    /** @return HasMany<PaymentGatewayEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentGatewayEvent::class, 'gateway_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'settlement_currency' => CurrencyCode::class,
            'status' => PaymentGatewayStatus::class,
            'amount_minor' => 'integer',
            'settlement_amount_minor' => 'integer',
            'initiated_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
