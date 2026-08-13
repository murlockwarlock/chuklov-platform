<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use InvalidArgumentException;

final readonly class ScenarioRuleConfiguration
{
    public function __construct(
        public string $ruleKey,
        public string $name,
        public ScenarioEventType $triggerEvent,
        public bool $isEnabled,
        public int $delayValue,
        public ScenarioDelayUnit $delayUnit,
        public ScenarioRulePurpose $purpose,
        public ScenarioConditionSet $conditions,
        public ScenarioRecipientStrategy $recipientStrategy,
        public ScenarioChannelPriority $channelPriority,
        public int $templateVersionId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $ruleKey = trim((string) ($data['rule_key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $triggerEvent = ScenarioEventType::tryFrom((string) ($data['trigger_event'] ?? ''));
        $delayUnit = ScenarioDelayUnit::tryFrom((string) ($data['delay_unit'] ?? ''));
        $purpose = ScenarioRulePurpose::tryFrom((string) ($data['purpose'] ?? ''));
        $delayValue = filter_var($data['delay_value'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $templateVersionId = filter_var($data['template_version_id'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if ($ruleKey === '' || mb_strlen($ruleKey) > 120 || preg_match('/^[a-z0-9._-]+$/', $ruleKey) !== 1) {
            throw new InvalidArgumentException('The scenario rule key is invalid.');
        }

        if ($name === '' || mb_strlen($name) > 160 || $triggerEvent === null || $delayUnit === null || $purpose === null) {
            throw new InvalidArgumentException('The scenario rule configuration is invalid.');
        }

        if ($delayValue === null || $delayValue < 0) {
            throw new InvalidArgumentException('The scenario delay is invalid.');
        }

        $delayUnit->toSeconds($delayValue);

        if ($templateVersionId === null || $templateVersionId < 1) {
            throw new InvalidArgumentException('The scenario template version is invalid.');
        }

        return new self(
            ruleKey: $ruleKey,
            name: $name,
            triggerEvent: $triggerEvent,
            isEnabled: self::booleanValue($data['is_enabled'] ?? false),
            delayValue: $delayValue,
            delayUnit: $delayUnit,
            purpose: $purpose,
            conditions: ScenarioConditionSet::from(is_array($data['conditions'] ?? null) ? $data['conditions'] : []),
            recipientStrategy: ScenarioRecipientStrategy::from(is_array($data['recipient_strategy'] ?? null) ? $data['recipient_strategy'] : []),
            channelPriority: ScenarioChannelPriority::from(is_array($data['channel_priority'] ?? null) ? $data['channel_priority'] : []),
            templateVersionId: $templateVersionId,
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'rule_key' => $this->ruleKey,
            'name' => $this->name,
            'trigger_event' => $this->triggerEvent->value,
            'is_enabled' => $this->isEnabled,
            'delay_value' => $this->delayValue,
            'delay_unit' => $this->delayUnit->value,
            'purpose' => $this->purpose->value,
            'conditions' => $this->conditions->toArray(),
            'recipient_strategy' => $this->recipientStrategy->toArray(),
            'channel_priority' => $this->channelPriority->channels,
            'template_version_id' => $this->templateVersionId,
        ];
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new InvalidArgumentException('The scenario enabled state is invalid.');
        }

        return $parsed;
    }
}
