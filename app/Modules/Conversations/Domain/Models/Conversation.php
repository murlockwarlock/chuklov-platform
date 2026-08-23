<?php

namespace App\Modules\Conversations\Domain\Models;

use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ConversationType $conversation_type
 * @property ConversationAutomationState $automation_state
 * @property int $context_epoch
 */
#[Fillable(['channel', 'external_key', 'conversation_type', 'automation_state', 'context_epoch', 'started_at', 'last_message_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

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

    /** @return HasMany<ConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /** @return HasMany<ConversationBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(ConversationBinding::class);
    }

    /** @return HasMany<CompanionTurn, $this> */
    public function companionTurns(): HasMany
    {
        return $this->hasMany(CompanionTurn::class);
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'conversation_type' => ConversationType::class,
            'automation_state' => ConversationAutomationState::class,
            'context_epoch' => 'integer',
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }
}
