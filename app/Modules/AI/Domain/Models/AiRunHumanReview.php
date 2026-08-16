<?php

namespace App\Modules\AI\Domain\Models;

use App\Models\User;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $ai_run_id
 * @property int $review_step
 * @property HumanReviewDecision $decision
 * @property int|null $reviewer_user_id
 * @property string|null $safe_reason_code
 * @property CarbonInterface $reviewed_at
 * @property-read Organization $organization
 * @property-read AiRun $run
 * @property-read User|null $reviewer
 */
#[Fillable([
    'organization_id',
    'ai_run_id',
    'review_step',
    'decision',
    'reviewer_user_id',
    'safe_reason_code',
    'reviewed_at',
])]
class AiRunHumanReview extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'review_step' => 'integer',
            'decision' => HumanReviewDecision::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
