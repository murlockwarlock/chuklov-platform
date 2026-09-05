<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'partner_profile_id',
    'partner_client_id',
    'referral_identity_id',
    'public_token',
    'name',
    'channel',
    'is_active',
    'is_default',
    'disabled_at',
    'created_by_user_id',
    'disabled_by_user_id',
])]
class ReferralCampaignLink extends Model
{
    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ReferralPartnerProfile, $this> */
    public function partnerProfile(): BelongsTo
    {
        return $this->belongsTo(ReferralPartnerProfile::class, 'partner_profile_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function partnerClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'partner_client_id');
    }

    /** @return BelongsTo<ClientReferralIdentity, $this> */
    public function referralIdentity(): BelongsTo
    {
        return $this->belongsTo(ClientReferralIdentity::class, 'referral_identity_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by_user_id');
    }

    /** @return HasMany<ReferralLinkVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(ReferralLinkVisit::class, 'campaign_link_id');
    }

    /** @return HasMany<ReferralRelationship, $this> */
    public function relationships(): HasMany
    {
        return $this->hasMany(ReferralRelationship::class, 'referral_campaign_link_id');
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    protected function casts(): array
    {
        return [
            'channel' => ReferralCampaignChannel::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'disabled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
