<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $sequence */
#[Fillable(['organization_id', 'turn_id', 'conversation_message_id', 'sequence', 'request_hash'])]
class CompanionTurnMessage extends Model
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

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }
}
