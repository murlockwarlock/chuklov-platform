<?php

namespace App\Modules\Tracker\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int $id
 * @property-read int $organization_id
 * @property-read int $client_id
 * @property string $title
 * @property TrackerTaskType $task_type
 * @property TrackerTaskFrequency $frequency
 * @property int|null $week_day
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property bool $active
 * @property int $display_order
 */
#[Fillable(['title', 'task_type', 'frequency', 'week_day', 'starts_on', 'ends_on', 'active', 'display_order', 'created_by_user_id'])]
class TrackerTask extends Model
{
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<TrackerTaskEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TrackerTaskEntry::class);
    }

    protected function casts(): array
    {
        return [
            'task_type' => TrackerTaskType::class,
            'frequency' => TrackerTaskFrequency::class,
            'week_day' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'active' => 'boolean',
            'display_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
