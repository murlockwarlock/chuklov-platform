<?php

namespace App\Modules\Tracker\Domain\Models;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $starts_at @property CarbonImmutable $ends_at @property CarbonImmutable|null $ended_at @property int|null $applied_price_minor @property int|null $applied_duration_days @property string|null $applied_plan_name @property string|null $applied_monthly_practice @property CurrencyCode|string|null $applied_currency */
#[Fillable(['active', 'starts_at', 'ends_at', 'source', 'reason', 'applied_price_minor', 'applied_currency', 'applied_duration_days', 'applied_monthly_practice', 'created_by_user_id', 'ended_at'])]
class TrackerEntitlement extends Model
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

    /** @return BelongsTo<TrackerPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrackerPlan::class, 'tracker_plan_id');
    }

    /** @return BelongsTo<TrackerPlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(TrackerPlanVersion::class, 'tracker_plan_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'source' => TrackerEntitlementSource::class,
            'applied_price_minor' => 'integer',
            'applied_duration_days' => 'integer',
            'applied_monthly_practice' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
