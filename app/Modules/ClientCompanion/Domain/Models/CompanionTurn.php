<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\ClientCompanion\Domain\Enums\CompanionImageReferenceMode;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $conversation_id
 * @property int $client_id
 * @property int $context_epoch
 * @property int $sequence
 * @property CompanionTurnStatus $status
 * @property int|null $ai_run_id
 * @property string $origin_channel
 * @property string|null $transport_chat_id
 * @property string|null $input_modality
 * @property CompanionImageReferenceMode $image_reference_mode
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $processing_started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $escalated_at
 * @property CarbonInterface|null $burst_expires_at
 * @property CarbonInterface|null $album_assembly_deadline_at
 * @property int|null $album_recovery_message_id
 * @property CarbonInterface|null $album_incomplete_at
 * @property CarbonInterface|null $execution_deadline_at
 * @property CarbonInterface|null $processing_lease_expires_at
 * @property CarbonInterface|null $sealed_at
 */
#[Fillable([
    'organization_id', 'client_id', 'conversation_id', 'sequence', 'context_epoch', 'inbound_message_id',
    'outbound_message_id', 'album_recovery_message_id', 'ai_run_id', 'origin_channel', 'origin_external_id', 'transport_chat_id',
    'idempotency_key', 'request_hash', 'status', 'failure_code', 'processing_lease_token',
    'burst_expires_at', 'album_assembly_deadline_at', 'burst_message_count', 'burst_text_characters',
    'input_modality', 'image_reference_mode', 'media_group_id', 'input_item_count', 'input_total_bytes', 'input_failure_code', 'sealed_at',
    'processing_lease_expires_at', 'execution_deadline_at', 'typing_owner_token', 'typing_heartbeat_sequence', 'typing_active',
    'typing_chat_id', 'accepted_at', 'processing_started_at', 'completed_at', 'failed_at', 'album_incomplete_at', 'escalated_at',
])]
class CompanionTurn extends Model
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

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'inbound_message_id');
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'outbound_message_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /** @return HasMany<CompanionDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CompanionDelivery::class, 'turn_id');
    }

    /** @return HasOne<CompanionEscalation, $this> */
    public function escalation(): HasOne
    {
        return $this->hasOne(CompanionEscalation::class, 'turn_id');
    }

    /** @return HasOne<CompanionFeedback, $this> */
    public function feedback(): HasOne
    {
        return $this->hasOne(CompanionFeedback::class, 'turn_id');
    }

    /** @return HasMany<CompanionTurnMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CompanionTurnMessage::class, 'turn_id')->orderBy('sequence');
    }

    /** @return HasMany<CompanionMessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(CompanionMessageAttachment::class, 'turn_id')->orderBy('source_ordinal')->orderBy('item_index');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function leaseIsExpired(?CarbonInterface $now = null): bool
    {
        return $this->processing_lease_expires_at === null
            || $this->processing_lease_expires_at->lessThanOrEqualTo($now ?? now());
    }

    protected function casts(): array
    {
        return [
            'status' => CompanionTurnStatus::class,
            'image_reference_mode' => CompanionImageReferenceMode::class,
            'context_epoch' => 'integer',
            'sequence' => 'integer',
            'typing_heartbeat_sequence' => 'integer',
            'burst_expires_at' => 'datetime',
            'album_assembly_deadline_at' => 'datetime',
            'burst_message_count' => 'integer',
            'burst_text_characters' => 'integer',
            'input_item_count' => 'integer',
            'input_total_bytes' => 'integer',
            'typing_active' => 'boolean',
            'accepted_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_lease_expires_at' => 'datetime',
            'execution_deadline_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'album_incomplete_at' => 'datetime',
            'escalated_at' => 'datetime',
            'sealed_at' => 'datetime',
        ];
    }
}
