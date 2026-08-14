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
        public int $maxOccurrences = 1,
        public ?int $repeatIntervalValue = null,
        public ?ScenarioDelayUnit $repeatIntervalUnit = null,
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
        $maxOccurrences = filter_var($data['max_occurrences'] ?? 1, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $repeatIntervalValue = filter_var($data['repeat_interval_value'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $repeatIntervalUnit = $data['repeat_interval_unit'] ?? null;
        $repeatIntervalUnit = $repeatIntervalUnit === null || $repeatIntervalUnit === ''
            ? null
            : ScenarioDelayUnit::tryFrom((string) $repeatIntervalUnit);

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

        if ($maxOccurrences === null || $maxOccurrences < 1 || $maxOccurrences > 100) {
            throw new InvalidArgumentException('The scenario occurrence limit is invalid.');
        }

        if ($maxOccurrences > 1 && ($repeatIntervalValue === null || $repeatIntervalValue < 1 || $repeatIntervalUnit === null)) {
            throw new InvalidArgumentException('The scenario repeat interval is required.');
        }

        if ($maxOccurrences === 1 && ($repeatIntervalValue !== null || $repeatIntervalUnit !== null)) {
            throw new InvalidArgumentException('The scenario repeat interval is not allowed for one occurrence.');
        }

        if ($repeatIntervalValue !== null && $repeatIntervalUnit !== null) {
            $repeatIntervalUnit->toSeconds($repeatIntervalValue);
        }

        if (array_key_exists('conditions', $data) && ! is_array($data['conditions'])) {
            throw new InvalidArgumentException('The scenario condition set is invalid.');
        }

        return new self(
            ruleKey: $ruleKey,
            name: $name,
            triggerEvent: $triggerEvent,
            isEnabled: self::booleanValue($data['is_enabled'] ?? false),
            delayValue: $delayValue,
            delayUnit: $delayUnit,
            purpose: $purpose,
            conditions: ScenarioConditionSet::from($data['conditions'] ?? []),
            recipientStrategy: ScenarioRecipientStrategy::from(is_array($data['recipient_strategy'] ?? null) ? $data['recipient_strategy'] : []),
            channelPriority: ScenarioChannelPriority::from(is_array($data['channel_priority'] ?? null) ? $data['channel_priority'] : []),
            templateVersionId: $templateVersionId,
            maxOccurrences: $maxOccurrences,
            repeatIntervalValue: $repeatIntervalValue,
            repeatIntervalUnit: $repeatIntervalUnit,
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
            'max_occurrences' => $this->maxOccurrences,
            'repeat_interval_value' => $this->repeatIntervalValue,
            'repeat_interval_unit' => $this->repeatIntervalUnit?->value,
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
