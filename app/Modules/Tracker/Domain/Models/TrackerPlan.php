<?php

namespace App\Modules\Tracker\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\TrackerPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property-read TrackerPlanVersion|null $currentVersion */
#[Fillable(['name', 'is_active', 'is_visible', 'current_version_id', 'archived_at'])]
class TrackerPlan extends Model
{
    /** @use HasFactory<TrackerPlanFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<TrackerPlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TrackerPlanVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<TrackerPlanVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TrackerPlanVersion::class, 'current_version_id');
    }

    /** @return HasOne<TrackerPlanVersion, $this> */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(TrackerPlanVersion::class, 'tracker_plan_id')->latestOfMany('version');
    }

    protected static function newFactory(): TrackerPlanFactory
    {
        return TrackerPlanFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
