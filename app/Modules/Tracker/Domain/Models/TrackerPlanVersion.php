<?php

namespace App\Modules\Tracker\Domain\Models;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property int $price_minor @property CurrencyCode $currency @property int $duration_days @property bool $included_access @property int $display_order */
#[Fillable(['version', 'price_minor', 'currency', 'duration_days', 'description', 'included_access', 'display_order', 'created_by_user_id'])]
class TrackerPlanVersion extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<TrackerPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrackerPlan::class, 'tracker_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Tracker plan versions are immutable.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Tracker plan versions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'duration_days' => 'integer',
            'included_access' => 'boolean',
            'display_order' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function currencyCode(): CurrencyCode
    {
        $currency = $this->getRawOriginal('currency');

        return CurrencyCode::from(is_string($currency) ? $currency : (string) $this->currency);
    }
}
