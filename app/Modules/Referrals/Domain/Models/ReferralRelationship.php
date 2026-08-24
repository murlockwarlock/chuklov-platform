<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $registered_at
 * @property ReferralEstablishmentMethod $establishment_method
 * @property int|null $commercial_evidence_count
 * @property Carbon|null $commercial_evidence_max_observed_at
 * @property-read Client|null $referred
 */
#[Fillable([])]
class ReferralRelationship extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referrer_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referred_client_id');
    }

    /** @return HasMany<ReferralCommercialEvidence, $this> */
    public function commercialEvidence(): HasMany
    {
        return $this->hasMany(ReferralCommercialEvidence::class, 'referral_relationship_id');
    }

    protected function casts(): array
    {
        return [
            'establishment_method' => ReferralEstablishmentMethod::class,
            'registered_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
