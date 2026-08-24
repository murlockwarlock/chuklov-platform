<?php

namespace App\Modules\ClientCompanion\Domain\Models;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CompanionEscalationReason $reason
 * @property CompanionEscalationStatus $status
 * @property CarbonInterface $opened_at
 * @property CarbonInterface|null $resolved_at
 */
#[Fillable([
    'organization_id', 'client_id', 'conversation_id', 'turn_id', 'ai_run_id', 'reason', 'status',
    'safe_metadata', 'resolved_by_user_id', 'opened_at', 'resolved_at',
])]
class CompanionEscalation extends Model
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

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function reasonLabel(): string
    {
        return $this->reason->label();
    }

    protected function casts(): array
    {
        return [
            'reason' => CompanionEscalationReason::class,
            'status' => CompanionEscalationStatus::class,
            'safe_metadata' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
