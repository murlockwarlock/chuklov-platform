<?php

namespace App\Modules\Analytics\Domain\Models;

use App\Models\User;
use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AnalyticsTagCandidateStatus $status
 * @property int|null $suggested_existing_id
 * @property int $version
 */
#[Fillable([])]
class AnalyticsTagCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AnalyticsTagCandidateStatus::class,
            'reviewed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AnalyticsClassificationRun, $this> */
    public function classificationRun(): BelongsTo
    {
        return $this->belongsTo(AnalyticsClassificationRun::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
