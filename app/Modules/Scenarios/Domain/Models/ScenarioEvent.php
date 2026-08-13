<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use Database\Factories\ScenarioEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ScenarioEventType $event_name
 * @property ScenarioEventStatus $status
 * @property array<string, mixed> $payload
 */
#[Fillable([
    'event_name',
    'aggregate_type',
    'aggregate_id',
    'occurred_at',
    'payload',
    'correlation_id',
    'causation_id',
    'idempotency_key',
    'status',
    'available_at',
    'processing_started_at',
    'processed_at',
    'last_error_code',
])]
class ScenarioEvent extends Model
{
    /** @use HasFactory<ScenarioEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ScenarioAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ScenarioAction::class);
    }

    protected static function newFactory(): ScenarioEventFactory
    {
        return ScenarioEventFactory::new();
    }

    protected function casts(): array
    {
        return [
            'event_name' => ScenarioEventType::class,
            'status' => ScenarioEventStatus::class,
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'available_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }
}
