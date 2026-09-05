<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'campaign_link_id',
    'session_hash',
    'occurred_at',
])]
class ReferralLinkVisit extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ReferralCampaignLink, $this> */
    public function campaignLink(): BelongsTo
    {
        return $this->belongsTo(ReferralCampaignLink::class, 'campaign_link_id');
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
