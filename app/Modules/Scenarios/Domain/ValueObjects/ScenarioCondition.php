<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use InvalidArgumentException;

final readonly class ScenarioCondition
{
    public function __construct(
        public string $type,
        public ScenarioConditionOperator $operator,
        public mixed $value,
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $type = trim((string) ($data['type'] ?? ''));
        $operator = ScenarioConditionOperator::tryFrom((string) ($data['operator'] ?? ''));

        if ($type === '' || mb_strlen($type) > 64 || preg_match('/^[a-z0-9_.-]+$/', $type) !== 1 || $operator === null) {
            throw new InvalidArgumentException('The scenario condition is invalid.');
        }

        $value = $data['value'] ?? null;

        if ($operator === ScenarioConditionOperator::Exists) {
            if ($value !== null && $value !== '') {
                throw new InvalidArgumentException('The exists condition does not accept a value.');
            }

            $value = null;
        } elseif ($operator === ScenarioConditionOperator::In) {
            if (! is_array($value) || ! array_is_list($value) || $value === []) {
                throw new InvalidArgumentException('The scenario condition list is invalid.');
            }

            foreach ($value as $item) {
                if (! is_scalar($item)) {
                    throw new InvalidArgumentException('The scenario condition list contains an invalid value.');
                }
            }
        } elseif (! is_scalar($value)) {
            throw new InvalidArgumentException('The scenario condition value is invalid.');
        }

        return new self($type, $operator, $value);
    }

    /** @return array{type: string, operator: string, value: mixed} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }
}
