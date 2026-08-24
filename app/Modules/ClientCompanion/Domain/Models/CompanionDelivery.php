<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CompanionDeliveryStatus $status
 * @property int $chunk_index
 * @property int $chunk_count
 * @property int $attempt_count
 * @property string|null $processing_lease_token
 * @property CarbonInterface|null $processing_lease_expires_at
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $uncertain_at
 */
#[Fillable([
    'organization_id', 'turn_id', 'conversation_message_id', 'channel', 'recipient_external_id',
    'chunk_index', 'chunk_count', 'status', 'attempt_count', 'provider_reference', 'last_error_code',
    'processing_lease_token', 'processing_lease_expires_at', 'next_attempt_at', 'delivered_at',
    'uncertain_at',
])]
class CompanionDelivery extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<CompanionTurn, $this> */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(CompanionTurn::class, 'turn_id');
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class);
    }

    public function leaseIsExpired(?CarbonInterface $now = null): bool
    {
        return $this->processing_lease_expires_at === null
            || $this->processing_lease_expires_at->lessThanOrEqualTo($now ?? now());
    }

    protected function casts(): array
    {
        return [
            'status' => CompanionDeliveryStatus::class,
            'chunk_index' => 'integer',
            'chunk_count' => 'integer',
            'attempt_count' => 'integer',
            'processing_lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'uncertain_at' => 'datetime',
        ];
    }
}
