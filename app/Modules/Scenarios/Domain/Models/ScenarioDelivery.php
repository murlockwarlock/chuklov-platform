<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use Database\Factories\ScenarioDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ScenarioDeliveryStatus $status
 */
#[Fillable([
    'channel',
    'priority',
    'status',
    'idempotency_key',
    'next_attempt_at',
    'processing_started_at',
    'delivered_at',
    'last_error_code',
    'terminal_reason',
    'provider_reference',
])]
class ScenarioDelivery extends Model
{
    /** @use HasFactory<ScenarioDeliveryFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ScenarioAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ScenarioAction::class, 'scenario_action_id');
    }

    /** @return HasMany<ScenarioDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(ScenarioDeliveryAttempt::class);
    }

    protected static function newFactory(): ScenarioDeliveryFactory
    {
        return ScenarioDeliveryFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => ScenarioDeliveryStatus::class,
            'priority' => 'integer',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
