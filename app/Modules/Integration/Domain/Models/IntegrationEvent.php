<?php

namespace App\Modules\Integration\Domain\Models;

use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property IntegrationEventType $event_type
 * @property IntegrationEventStatus $status
 * @property array<string, scalar|null> $payload
 * @property Carbon $occurred_at
 * @property Carbon $available_at
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $processed_at
 */
#[Fillable([])]
class IntegrationEvent extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'event_type' => IntegrationEventType::class,
            'status' => IntegrationEventStatus::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'available_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
