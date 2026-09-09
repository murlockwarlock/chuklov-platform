<?php

namespace App\Modules\Tracker\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Domain\Enums\TrackerTaskEntryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $tracker_task_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $recorded_at
 * @property TrackerTaskEntryStatus $status
 * @property string|null $comment
 * @property-read TrackerTask|null $task
 */
#[Fillable(['period_start', 'status', 'comment', 'recorded_at', 'source'])]
class TrackerTaskEntry extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<TrackerTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(TrackerTask::class, 'tracker_task_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'status' => TrackerTaskEntryStatus::class,
            'comment' => 'encrypted',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
