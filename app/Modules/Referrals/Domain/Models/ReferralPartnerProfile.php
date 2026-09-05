<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'client_id',
    'status',
    'activated_at',
    'activation_source',
    'activated_by_user_id',
    'deactivated_at',
    'deactivated_by_user_id',
])]
class ReferralPartnerProfile extends Model
{
    protected $attributes = [
        'status' => ReferralPartnerStatus::Active->value,
    ];

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

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }

    /** @return HasMany<ReferralCampaignLink, $this> */
    public function campaignLinks(): HasMany
    {
        return $this->hasMany(ReferralCampaignLink::class, 'partner_profile_id');
    }

    /** @return HasMany<ReferralCampaignLink, $this> */
    public function activeCampaignLinks(): HasMany
    {
        return $this->campaignLinks()->where('is_active', true);
    }

    public function isActive(): bool
    {
        return $this->status === ReferralPartnerStatus::Active;
    }

    protected function casts(): array
    {
        return [
            'status' => ReferralPartnerStatus::class,
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReferralPartnerStatus::Active->value);
    }
}
