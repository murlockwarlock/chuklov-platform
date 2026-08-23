<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
use App\Modules\ClientCompanion\Domain\Models\CompanionFeedback;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class RecordCompanionFeedback
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client, int $messageId, CompanionFeedbackValue $value, ?string $reason = null): CompanionFeedback
    {
        $organizationId = $this->context->id();
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The feedback target is outside the organization.');
        }

        $message = ConversationMessage::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->whereKey($messageId)
            ->first();
        if ($message === null || $message->author_type !== ConversationAuthorType::Ai
            || ! $message->conversation()->where('conversation_type', ConversationType::ClientCompanion)->exists()) {
            throw new AuthorizationException('Feedback is only available for the client’s Companion response.');
        }

        $turn = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $message->conversation_id)
            ->where('outbound_message_id', $message->getKey())
            ->first();
        if ($turn === null) {
            throw new AuthorizationException('The feedback target is not a Companion response.');
        }

        if ($reason !== null && mb_strlen($reason) > 64) {
            throw ValidationException::withMessages(['reason' => 'Выберите допустимую причину оценки.']);
        }

        return CompanionFeedback::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'client_id' => $client->getKey(),
                'message_id' => $message->getKey(),
            ],
            [
                'conversation_id' => $message->conversation_id,
                'turn_id' => $turn->getKey(),
                'ai_run_id' => $turn->ai_run_id,
                'value' => $value,
                'reason' => $reason,
            ],
        );
    }
}
