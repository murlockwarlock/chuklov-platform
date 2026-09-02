<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Scenarios\Domain\Enums\ScenarioAudienceType;
use InvalidArgumentException;

final readonly class ScenarioRecipientStrategy
{
    /** @param list<int|string|OrganizationRole> $values */
    public function __construct(
        public ScenarioAudienceType $type,
        public array $values = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $type = ScenarioAudienceType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            throw new InvalidArgumentException('The scenario recipient strategy is invalid.');
        }

        $values = match ($type) {
            ScenarioAudienceType::Client => [],
            ScenarioAudienceType::Members => self::memberIds($data['user_ids'] ?? []),
            ScenarioAudienceType::Roles => self::roles($data['roles'] ?? []),
            ScenarioAudienceType::AssignedSpecialist => [],
        };

        return new self($type, $values);
    }

    /** @return array{type: string, user_ids?: list<int>, roles?: list<string>} */
    public function toArray(): array
    {
        return match ($this->type) {
            ScenarioAudienceType::Client => ['type' => $this->type->value],
            ScenarioAudienceType::Members => ['type' => $this->type->value, 'user_ids' => $this->memberValues()],
            ScenarioAudienceType::Roles => ['type' => $this->type->value, 'roles' => $this->roleValues()],
            ScenarioAudienceType::AssignedSpecialist => ['type' => $this->type->value],
        };
    }

    /** @return list<int> */
    private static function memberIds(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > 100) {
            throw new InvalidArgumentException('The scenario member recipient list is invalid.');
        }

        $ids = [];

        foreach ($values as $value) {
            if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                throw new InvalidArgumentException('The scenario member recipient list is invalid.');
            }

            $id = (int) $value;

            if ($id < 1 || in_array($id, $ids, true)) {
                throw new InvalidArgumentException('The scenario member recipient list is invalid.');
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /** @return list<OrganizationRole> */
    private static function roles(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            throw new InvalidArgumentException('The scenario role recipient list is invalid.');
        }

        $unique = [];

        foreach ($values as $value) {
            $role = OrganizationRole::tryFrom((string) $value);

            if ($role === null) {
                throw new InvalidArgumentException('The scenario role recipient list is invalid.');
            }

            $unique[$role->value] = $role;
        }

        return array_values($unique);
    }

    /** @return list<int> */
    private function memberValues(): array
    {
        $values = [];

        foreach ($this->values as $value) {
            if (is_int($value) || is_string($value)) {
                $values[] = (int) $value;
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function roleValues(): array
    {
        $values = [];

        foreach ($this->values as $value) {
            if ($value instanceof OrganizationRole) {
                $values[] = $value->value;
            }
        }

        return $values;
    }
}
