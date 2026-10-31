<?php

namespace App\Modules\Analytics\Domain\Models;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ClassificationRunStatus $status
 * @property array<int, array{id: int, label: string}>|null $suggestions
 */
#[Fillable([])]
class AnalyticsClassificationRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ClassificationRunStatus::class,
            'suggestions' => 'array',
            'attempt_count' => 'integer',
            'accepted_at' => 'immutable_datetime',
        ];
    }

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

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
