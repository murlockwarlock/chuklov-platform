<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $turn_id
 * @property int $attempt_number
 * @property string $execution_key
 * @property CompanionTurnAttemptStatus $status
 * @property int|null $ai_run_id
 * @property string|null $failure_code
 * @property int|null $output_message_id
 * @property int|null $retry_requested_from_message_id
 * @property CarbonInterface|null $execution_deadline_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 */
#[Fillable([
    'organization_id', 'turn_id', 'attempt_number', 'execution_key', 'status', 'ai_run_id',
    'failure_code', 'output_message_id', 'retry_requested_from_message_id', 'execution_deadline_at',
    'started_at', 'completed_at',
])]
class CompanionTurnAttempt extends Model
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

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function outputMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'output_message_id');
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function retryRequestedFromMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'retry_requested_from_message_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CompanionTurnAttemptStatus::class,
            'attempt_number' => 'integer',
            'execution_deadline_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
