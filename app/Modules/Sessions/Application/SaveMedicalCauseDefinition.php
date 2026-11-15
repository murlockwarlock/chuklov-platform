<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveMedicalCauseDefinition
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, array $attributes, ?int $causeId = null): MedicalCauseDefinition
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $organizationId = (int) $organization->getKey();
        $causeId ??= isset($attributes['id']) ? (int) $attributes['id'] : null;
        $normalized = $this->normalize($attributes);

        return DB::transaction(function () use ($actor, $organization, $organizationId, $causeId, $normalized): MedicalCauseDefinition {
            /** @var Collection<int, MedicalCauseDefinition> $lockedCauses */
            $lockedCauses = MedicalCauseDefinition::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->get();
            $existing = $causeId === null
                ? null
                : $lockedCauses->firstWhere('id', $causeId);
            if ($causeId !== null && $existing === null) {
                throw ValidationException::withMessages(['cause' => 'Причина не найдена.']);
            }
            if ($existing?->merged_into_id !== null) {
                throw ValidationException::withMessages(['cause' => 'Объединённую причину нельзя редактировать.']);
            }

            $identityValues = $this->identityValues($normalized['name'], $normalized['synonyms']);
            $duplicate = $lockedCauses->first(function (MedicalCauseDefinition $cause) use ($identityValues, $causeId): bool {
                if ($causeId !== null && (int) $cause->getKey() === $causeId) {
                    return false;
                }
                if ($cause->merged_into_id !== null) {
                    return false;
                }

                $existingValues = $this->identityValues((string) $cause->name, $this->synonyms($cause->synonyms));

                return array_intersect($identityValues, $existingValues) !== [];
            });
            if ($duplicate instanceof MedicalCauseDefinition) {
                throw ValidationException::withMessages([
                    'cause' => 'Такая причина или её синоним уже есть в справочнике.',
                ]);
            }

            $model = $existing ?? new MedicalCauseDefinition;
            $model->forceFill([
                'organization_id' => $organizationId,
                'name' => $normalized['name'],
                'synonyms' => $normalized['synonyms'],
                'group_name' => $normalized['group_name'],
                'tooltip' => $normalized['tooltip'],
                'moderation_status' => $normalized['moderation_status'],
                'is_active' => $normalized['is_active'],
                'sort_order' => $normalized['sort_order'],
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.catalog.updated',
                targetType: MedicalCauseDefinition::class,
                targetId: (string) $model->getKey(),
                metadata: [],
            );

            return $model->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{name:string,synonyms:list<string>,group_name:?string,tooltip:?string,moderation_status:string,is_active:bool,sort_order:int}
     */
    private function normalize(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Укажите название причины длиной до 255 символов.']);
        }

        $group = $this->optionalText($attributes['group_name'] ?? null, 255, 'group_name');
        $tooltip = $this->optionalText($attributes['tooltip'] ?? null, 4000, 'tooltip');
        $status = trim((string) ($attributes['moderation_status'] ?? $attributes['status'] ?? MedicalCauseDefinition::MODERATION_ACTIVE));
        if ($status === 'pending') {
            $status = MedicalCauseDefinition::MODERATION_PENDING;
        }
        if (! in_array($status, [
            MedicalCauseDefinition::MODERATION_ACTIVE,
            MedicalCauseDefinition::MODERATION_PENDING,
            MedicalCauseDefinition::MODERATION_HIDDEN,
        ], true)) {
            throw ValidationException::withMessages(['moderation_status' => 'Укажите корректный статус причины.']);
        }

        $isActive = array_key_exists('is_active', $attributes)
            ? filter_var($attributes['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false
            : $status !== MedicalCauseDefinition::MODERATION_HIDDEN;
        if ($status === MedicalCauseDefinition::MODERATION_HIDDEN) {
            $isActive = false;
        }

        return [
            'name' => $name,
            'synonyms' => MedicalCauseDirectory::normalizeSynonyms($attributes['synonyms'] ?? []),
            'group_name' => $group,
            'tooltip' => $tooltip,
            'moderation_status' => $status,
            'is_active' => $isActive,
            'sort_order' => max(0, (int) ($attributes['sort_order'] ?? 0)),
        ];
    }

    /**
     * @param  list<string>  $synonyms
     * @return list<string>
     */
    private function identityValues(string $name, array $synonyms): array
    {
        return array_values(array_unique(array_filter([
            MedicalCauseDirectory::normalizeIdentityValue($name),
            ...array_map(static fn (string $synonym): string => MedicalCauseDirectory::normalizeIdentityValue($synonym), $synonyms),
        ])));
    }

    /** @return list<string> */
    private function synonyms(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function optionalText(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'Значение должно быть текстом.']);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages([$field => "Значение не может быть длиннее {$maxLength} символов."]);
        }

        return $value;
    }
}
