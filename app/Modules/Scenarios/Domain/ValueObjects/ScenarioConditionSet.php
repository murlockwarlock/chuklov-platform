<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ScenarioConditionSet
{
    /** @param list<ScenarioCondition> $conditions */
    public function __construct(public array $conditions) {}

    /** @param array<int, mixed> $data */
    public static function from(array $data): self
    {
        if (! array_is_list($data) || count($data) > 20) {
            throw new InvalidArgumentException('The scenario condition set is invalid.');
        }

        $conditions = [];

        foreach ($data as $condition) {
            if (! is_array($condition)) {
                throw new InvalidArgumentException('The scenario condition set is invalid.');
            }

            $conditions[] = ScenarioCondition::from($condition);
        }

        return new self($conditions);
    }

    /** @return list<array{type: string, operator: string, value: mixed}> */
    public function toArray(): array
    {
        return array_map(
            static fn (ScenarioCondition $condition): array => $condition->toArray(),
            $this->conditions,
        );
    }
}
