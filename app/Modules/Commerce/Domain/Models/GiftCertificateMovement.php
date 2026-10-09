<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $certificate_id
 * @property GiftCertificateMovementType $movement_type
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property int|null $from_holder_client_id
 * @property int|null $to_holder_client_id
 * @property int|null $claim_id
 * @property int|null $redemption_id
 * @property int|null $reverses_movement_id
 * @property int|null $actor_user_id
 * @property Carbon $occurred_at
 * @property User|null $actor
 */
#[Fillable([])]
class GiftCertificateMovement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gift_certificate_movements';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<GiftCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(GiftCertificate::class, 'certificate_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function fromHolder(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'from_holder_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function toHolder(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'to_holder_client_id');
    }

    /** @return BelongsTo<GiftCertificateClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(GiftCertificateClaim::class, 'claim_id');
    }

    /** @return BelongsTo<GiftCertificateRedemption, $this> */
    public function redemption(): BelongsTo
    {
        return $this->belongsTo(GiftCertificateRedemption::class, 'redemption_id');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @param  Builder<GiftCertificateMovement>  $query
     * @return Builder<GiftCertificateMovement>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'movement_type' => GiftCertificateMovementType::class,
            'currency' => CurrencyCode::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
