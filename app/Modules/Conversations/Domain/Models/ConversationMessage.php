<?php

namespace App\Modules\Conversations\Domain\Models;

use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'occurred_at' => 'datetime',
        ];
    }
}
