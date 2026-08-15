<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $client_id
 * @property int $booking_id
 * @property int $service_id
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property int $base_amount_minor
 * @property CurrencyCode $base_currency
 * @property int $display_amount_minor
 * @property CurrencyCode $display_currency
 * @property int $payment_amount_minor
 * @property CurrencyCode $payment_currency
 * @property int $settlement_amount_minor
 * @property CurrencyCode $settlement_currency
 * @property array<string, mixed> $price_snapshot
 * @property array<string, mixed> $conversion_snapshots
 * @property Carbon $created_at
 */
#[Fillable([])]
class FinancialObligation extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<FinancialLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class, 'obligation_id');
    }

    /** @return HasMany<PaymentGatewayTransaction, $this> */
    public function gatewayTransactions(): HasMany
    {
        return $this->hasMany(PaymentGatewayTransaction::class, 'obligation_id');
    }

    /**
     * @param  Builder<FinancialObligation>  $query
     * @return Builder<FinancialObligation>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'base_currency' => CurrencyCode::class,
            'display_currency' => CurrencyCode::class,
            'payment_currency' => CurrencyCode::class,
            'settlement_currency' => CurrencyCode::class,
            'amount_minor' => 'integer',
            'base_amount_minor' => 'integer',
            'display_amount_minor' => 'integer',
            'payment_amount_minor' => 'integer',
            'settlement_amount_minor' => 'integer',
            'price_snapshot' => 'array',
            'conversion_snapshots' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
