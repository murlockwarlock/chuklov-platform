<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final readonly class MedicalCauseDirectory
{
    public function __construct(private OrganizationContext $context) {}

    /**
     * @return Collection<int, MedicalCauseDefinition>
     */
    public function search(
        int $organizationId,
        string $term = '',
        ?bool $active = true,
        ?string $moderationStatus = null,
        int $limit = 50,
    ): Collection {
        $this->assertCurrentOrganization($organizationId);
        $normalizedTerm = self::normalizeIdentityValue($term);
        $query = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereNull('merged_into_id')
            ->when($active !== null, static fn ($query) => $query->where('is_active', $active))
            ->when(
                $moderationStatus !== null,
                static fn ($query) => $query->where('moderation_status', $moderationStatus),
                static fn ($query) => $active === true
                    ? $query->where('moderation_status', MedicalCauseDefinition::MODERATION_ACTIVE)
                    : $query,
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id');

        $matches = $query->get()->filter(function (MedicalCauseDefinition $cause) use ($normalizedTerm): bool {
            if ($normalizedTerm === '') {
                return true;
            }

            $values = [
                $cause->name,
                $cause->group_name,
                $cause->tooltip,
                ...$this->synonyms($cause->synonyms),
            ];

            foreach ($values as $value) {
                if (str_contains(self::normalizeIdentityValue((string) $value), $normalizedTerm)) {
                    return true;
                }
            }

            return false;
        });

        return $matches->take(max(1, min($limit, 200)));
    }

    public function canonical(int $organizationId, int $causeId): MedicalCauseDefinition
    {
        $this->assertCurrentOrganization($organizationId);
        $cause = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereKey($causeId)
            ->first();
        if ($cause === null) {
            throw ValidationException::withMessages(['cause' => 'Выбранная причина не найдена.']);
        }

        $seen = [];
        while ($cause->merged_into_id !== null) {
            $causeId = (int) $cause->getKey();
            if (isset($seen[$causeId])) {
                throw ValidationException::withMessages(['cause' => 'В справочнике обнаружена циклическая связь причин.']);
            }
            $seen[$causeId] = true;

            $cause = MedicalCauseDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereKey((int) $cause->merged_into_id)
                ->first();
            if ($cause === null) {
                throw ValidationException::withMessages(['cause' => 'Каноническая причина не найдена.']);
            }
        }

        return $cause;
    }

    public function canonicalId(int $organizationId, int $causeId): int
    {
        return (int) $this->canonical($organizationId, $causeId)->getKey();
    }

    public function selectable(int $organizationId, int $causeId): MedicalCauseDefinition
    {
        $cause = $this->canonical($organizationId, $causeId);
        if (! $cause->is_active || $cause->moderation_status !== MedicalCauseDefinition::MODERATION_ACTIVE) {
            throw ValidationException::withMessages(['cause' => 'Причина недоступна для выбора.']);
        }

        return $cause;
    }

    /**
     * @param  array<int, mixed>  $synonyms
     * @return Collection<int, MedicalCauseDefinition>
     */
    public function duplicateCandidates(int $organizationId, string $name, array $synonyms = [], ?int $ignoreId = null): Collection
    {
        $this->assertCurrentOrganization($organizationId);
        $identityValues = array_values(array_unique([
            self::normalizeIdentityValue($name),
            ...array_map(static fn (mixed $synonym): string => self::normalizeIdentityValue((string) $synonym), $synonyms),
        ]));
        $identityValues = array_values(array_filter($identityValues, static fn (string $value): bool => $value !== ''));

        if ($identityValues === []) {
            return new Collection;
        }

        return MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereNull('merged_into_id')
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->orderBy('id')
            ->get()
            ->filter(function (MedicalCauseDefinition $cause) use ($identityValues): bool {
                $existing = [
                    self::normalizeIdentityValue((string) $cause->name),
                    ...array_map(static fn (string $synonym): string => self::normalizeIdentityValue($synonym), $this->synonyms($cause->synonyms)),
                ];

                return array_intersect($identityValues, $existing) !== [];
            })
            ->values();
    }

    /** @return list<string> */
    public static function normalizeSynonyms(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_string($value)
            ? preg_split('/\R/u', $value) ?: []
            : (is_array($value) ? $value : [$value]);
        $normalized = [];
        $seen = [];
        foreach ($values as $item) {
            if (! is_string($item)) {
                throw ValidationException::withMessages(['synonyms' => 'Синонимы должны быть текстом.']);
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (mb_strlen($item) > 255) {
                throw ValidationException::withMessages(['synonyms' => 'Синоним не может быть длиннее 255 символов.']);
            }
            $identity = self::normalizeIdentityValue($item);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    public static function normalizeIdentityValue(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return str_replace('ё', 'е', mb_strtolower($value, 'UTF-8'));
    }

    /** @return list<string> */
    private function synonyms(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function assertCurrentOrganization(int $organizationId): void
    {
        if ($this->context->id() !== $organizationId) {
            throw new AuthorizationException('The cause is outside the current organization.');
        }
    }
}
