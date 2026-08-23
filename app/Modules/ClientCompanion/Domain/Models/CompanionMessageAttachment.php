<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $source_ordinal
 * @property int $item_index
 * @property-read MedicalAttachment|null $medicalAttachment
 */
#[Fillable([
    'organization_id', 'client_id', 'conversation_id', 'turn_id', 'conversation_message_id',
    'medical_attachment_id', 'media_group_id', 'source_ordinal', 'item_index',
])]
class CompanionMessageAttachment extends Model
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

    /** @return BelongsTo<MedicalAttachment, $this> */
    public function medicalAttachment(): BelongsTo
    {
        return $this->belongsTo(MedicalAttachment::class);
    }

    protected function casts(): array
    {
        return [
            'source_ordinal' => 'integer',
            'item_index' => 'integer',
        ];
    }
}
