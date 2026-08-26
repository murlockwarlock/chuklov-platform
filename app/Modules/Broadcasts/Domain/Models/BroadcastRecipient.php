<?php

namespace App\Modules\Broadcasts\Domain\Models;

use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $campaign_id
 * @property int $snapshot_id
 * @property int|null $batch_id
 * @property int $client_id
 * @property string $kind
 * @property string $language
 * @property string|null $channel
 * @property string|null $external_id
 * @property BroadcastRecipientState $state
 * @property string|null $exclusion_code
 * @property string $idempotency_key
 * @property int $attempt_count
 * @property string|null $lease_token
 * @property array<string, mixed> $render_context
 * @property Carbon|null $claimed_at
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error_code
 */
class BroadcastRecipient extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<BroadcastCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'campaign_id');
    }

    /** @return HasMany<BroadcastDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(BroadcastDeliveryAttempt::class, 'recipient_id');
    }

    protected function casts(): array
    {
        return [
            'state' => BroadcastRecipientState::class,
            'attempt_count' => 'integer',
            'render_context' => 'array',
            'claimed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
