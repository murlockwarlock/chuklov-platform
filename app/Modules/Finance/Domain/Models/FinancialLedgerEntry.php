<?php

namespace App\Modules\Finance\Domain\Models;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $obligation_id
 * @property FinancialLedgerEntryType $entry_type
 * @property FinancialEntrySource $source
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property int $payment_amount_minor
 * @property CurrencyCode $payment_currency
 * @property int $base_amount_minor
 * @property CurrencyCode $base_currency
 * @property int $display_amount_minor
 * @property CurrencyCode $display_currency
 * @property int $settlement_amount_minor
 * @property CurrencyCode $settlement_currency
 * @property PaymentMethod|null $payment_method
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property int|null $corrects_ledger_entry_id
 */
#[Fillable([])]
class FinancialLedgerEntry extends Model
{
    public const UPDATED_AT = null;

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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<FinancialLedgerEntry, $this> */
    public function correctedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_ledger_entry_id');
    }

    /** @return HasOne<FinancialReceipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(FinancialReceipt::class, 'ledger_entry_id');
    }

    protected function casts(): array
    {
        return [
            'entry_type' => FinancialLedgerEntryType::class,
            'source' => FinancialEntrySource::class,
            'currency' => CurrencyCode::class,
            'payment_currency' => CurrencyCode::class,
            'base_currency' => CurrencyCode::class,
            'display_currency' => CurrencyCode::class,
            'settlement_currency' => CurrencyCode::class,
            'payment_method' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'payment_amount_minor' => 'integer',
            'base_amount_minor' => 'integer',
            'display_amount_minor' => 'integer',
            'settlement_amount_minor' => 'integer',
            'conversion_snapshot' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
