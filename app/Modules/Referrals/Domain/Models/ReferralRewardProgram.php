<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class ReferralRewardProgram extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ReferralRewardProgramVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ReferralRewardProgramVersion::class, 'current_version_id');
    }

    /** @return HasMany<ReferralRewardProgramVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ReferralRewardProgramVersion::class, 'program_id');
    }
}
