<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final class ClientMarketingConsentConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'client.marketing_consent';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        if ($context->client === null) {
            return false;
        }

        $consent = ClientConsent::query()
            ->where('organization_id', $context->event->organization_id)
            ->where('client_id', $context->client->getKey())
            ->where('subject', ConsentSubject::Marketing->value)
            ->latest('recorded_at')
            ->value('granted');
        $actual = $consent === null ? false : (bool) $consent;

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $consent !== null,
        };
    }
}
