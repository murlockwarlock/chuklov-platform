<?php

namespace App\Modules\Feedback\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $enabled
 * @property int $positive_threshold
 * @property bool $low_score_feedback_required
 * @property string|null $review_url_ru
 * @property string|null $review_url_en
 */
#[Fillable([])]
class FeedbackConfiguration extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'positive_threshold' => 'integer',
            'low_score_feedback_required' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
