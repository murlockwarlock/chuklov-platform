<?php

namespace App\Modules\Conversations\Domain\Models;

use App\Models\User;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $conversation_id
 * @property int $client_id
 * @property ConversationDirection $direction
 * @property ConversationAuthorType $author_type
 * @property int|null $author_user_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $occurred_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['channel', 'external_id', 'body', 'metadata', 'occurred_at'])]
class ConversationMessage extends Model
{
    /** @use HasFactory<ConversationMessageFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return HasMany<CompanionMessageAttachment, $this> */
    public function companionAttachments(): HasMany
    {
        return $this->hasMany(CompanionMessageAttachment::class, 'conversation_message_id');
    }

    protected static function newFactory(): ConversationMessageFactory
    {
        return ConversationMessageFactory::new();
    }

    protected function casts(): array
    {
        return [
            'direction' => ConversationDirection::class,
            'author_type' => ConversationAuthorType::class,
            'metadata' => 'array',
            'encryption_key_version' => 'integer',
            'companion_context_epoch' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
