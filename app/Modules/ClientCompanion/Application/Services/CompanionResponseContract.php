<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionSafeAction;
use InvalidArgumentException;

final class CompanionResponseContract
{
    /** @return array{decision: string, reply: string, handoff_reason: ?string, suggested_safe_actions: list<string>} */
    public function parse(AiRunResult $result): array
    {
        if ($result->status !== AiRunStatus::Succeeded || ! is_array($result->outputPayload)) {
            throw new InvalidArgumentException('The Companion response did not satisfy the structured response contract.');
        }

        $decision = $result->outputPayload['decision'] ?? null;
        $reply = $result->outputPayload['reply'] ?? null;
        $reason = $result->outputPayload['handoff_reason'] ?? null;
        $actions = $result->outputPayload['suggested_safe_actions'] ?? [];
        if (! is_string($decision) || ! in_array($decision, ['reply', 'handoff_required'], true)
            || ! is_string($reply) || mb_strlen($reply) > 40000
            || ($reason !== null && ! is_string($reason)) || ! is_array($actions) || count($actions) > 3) {
            throw new InvalidArgumentException('The Companion response did not satisfy the structured response contract.');
        }
        $safeActions = [];
        foreach ($actions as $action) {
            if (! is_string($action) || CompanionSafeAction::tryFrom($action) === null || in_array($action, $safeActions, true)) {
                throw new InvalidArgumentException('The Companion response contained an unknown safe action.');
            }
            $safeActions[] = $action;
        }

        $reply = trim($reply);
        if ($decision === 'reply' && $reply === '') {
            throw new InvalidArgumentException('The Companion response is empty.');
        }

        return [
            'decision' => $decision,
            'reply' => $reply,
            'handoff_reason' => $reason === null ? null : trim($reason),
            'suggested_safe_actions' => $safeActions,
        ];
    }
}
