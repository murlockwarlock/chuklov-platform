<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ScenarioDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property NotificationDeliveryOutcome $outcome
 */
#[Fillable(['attempt_number', 'outcome', 'error_code', 'provider_reference', 'attempted_at'])]
class ScenarioDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<ScenarioDeliveryAttemptFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ScenarioDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ScenarioDelivery::class, 'scenario_delivery_id');
    }

    protected static function newFactory(): ScenarioDeliveryAttemptFactory
    {
        return ScenarioDeliveryAttemptFactory::new();
    }

    protected function casts(): array
    {
        return [
            'outcome' => NotificationDeliveryOutcome::class,
            'attempt_number' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }
}
