<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CompanionFeedbackValue $value
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['organization_id', 'client_id', 'conversation_id', 'message_id', 'turn_id', 'ai_run_id', 'value', 'reason'])]
class CompanionFeedback extends Model
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
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id');
    }

    /** @return BelongsTo<CompanionTurn, $this> */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(CompanionTurn::class, 'turn_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    protected function casts(): array
    {
        return ['value' => CompanionFeedbackValue::class];
    }
}
