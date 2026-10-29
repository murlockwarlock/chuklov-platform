<?php

namespace App\Modules\Attribution\Application;

use App\Models\User;
use App\Modules\Attribution\Domain\Models\AttributionSourceMapping;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AttributionSourceCatalog
{
    /** @return list<array{source_key: string, label: string, aliases: list<array{value: string}>, is_active: bool, sort_order: int}> */
    public static function defaultRows(): array
    {
        return [
            [
                'source_key' => 'referral',
                'label' => 'Рекомендация знакомых',
                'aliases' => [['value' => 'referral'], ['value' => 'recommendation']],
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'source_key' => 'telegram_chuklov',
                'label' => 'Telegram-канал Чуклова',
                'aliases' => [['value' => 'telegram'], ['value' => 'telegram_channel']],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'source_key' => 'instagram',
                'label' => 'Instagram',
                'aliases' => [['value' => 'instagram'], ['value' => 'ig'], ['value' => 'insta']],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'source_key' => 'search',
                'label' => 'Поиск Yandex/Google',
                'aliases' => [['value' => 'yandex'], ['value' => 'google'], ['value' => 'yandex_search'], ['value' => 'google_search']],
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'source_key' => 'website',
                'label' => 'Сайт chuklov.com',
                'aliases' => [['value' => 'site'], ['value' => 'web'], ['value' => 'website'], ['value' => 'chuklov.com']],
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'source_key' => 'other',
                'label' => 'Другое',
                'aliases' => [['value' => 'other'], ['value' => 'другое']],
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];
    }

    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    /** @return list<array{source_key: string, label: string, aliases: list<string>, is_active: bool, sort_order: int}> */
    public function rows(int $organizationId): array
    {
        $rows = AttributionSourceMapping::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(static fn (AttributionSourceMapping $mapping): array => [
                'source_key' => (string) $mapping->source_key,
                'label' => (string) $mapping->label,
                'aliases' => self::normalizeAliases($mapping->aliases),
                'is_active' => (bool) $mapping->is_active,
                'sort_order' => (int) $mapping->sort_order,
            ])
            ->all();

        return array_values($rows);
    }

    /** @return array<string, string> */
    public function optionsForSelection(int $organizationId, ?string $selectedKey = null): array
    {
        $options = [];
        foreach ($this->rows($organizationId) as $row) {
            if (! $row['is_active'] && $row['source_key'] !== $selectedKey) {
                continue;
            }

            $options[$row['source_key']] = $row['label'].($row['is_active'] ? '' : ' ('.__('неактивен').')');
        }

        return $options;
    }

    public function ensureDefaults(User $actor): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        if ($this->rows((int) $organization->getKey()) !== []) {
            return;
        }

        $this->save($actor, self::defaultRows());
    }

    /** @param array<int, mixed> $rows */
    public function save(User $actor, array $rows): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $normalized = $this->normalize($rows);

        DB::transaction(function () use ($organization, $actor, $normalized): void {
            $lockedOrganization = $organization->newQuery()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $existing = AttributionSourceMapping::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('source_key');
            $keys = [];

            foreach ($normalized as $row) {
                $keys[$row['source_key']] = true;
                $mapping = $existing->get($row['source_key']) ?? new AttributionSourceMapping;
                $mapping->forceFill([
                    'organization_id' => $lockedOrganization->getKey(),
                    'source_key' => $row['source_key'],
                    'label' => $row['label'],
                    'aliases' => $row['aliases'],
                    'is_active' => $row['is_active'],
                    'sort_order' => $row['sort_order'],
                ])->save();
            }

            foreach ($existing as $key => $mapping) {
                if (! isset($keys[$key])) {
                    $mapping->forceFill(['is_active' => false])->save();
                }
            }

            $this->audit->handle(
                organization: $lockedOrganization,
                actor: $actor,
                action: 'attribution.source_catalog.updated',
                targetType: AttributionSourceMapping::class,
                targetId: (string) ($normalized[0]['source_key'] ?? 'catalog'),
                metadata: ['count' => count($normalized)],
            );
        });
    }

    /** @param array<int, mixed> $rows
     * @return list<array{source_key: string, label: string, aliases: list<string>, is_active: bool, sort_order: int}>
     */
    private function normalize(array $rows): array
    {
        $normalized = [];
        $keys = [];
        $seenAliases = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['sources' => 'Проверьте список источников.']);
            }
            $sourceKey = is_string($row['source_key'] ?? null) ? trim($row['source_key']) : '';
            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
            $sortOrder = filter_var($row['sort_order'] ?? $index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($sourceKey === '') {
                $sourceKey = 'source_'.Str::lower(Str::random(12));
            }
            if (preg_match('/^[a-z][a-z0-9_]{1,119}$/', $sourceKey) !== 1) {
                throw ValidationException::withMessages(['sources' => 'Укажите корректный идентификатор источника.']);
            }
            if ($label === '' || mb_strlen($label) > 160) {
                throw ValidationException::withMessages(['sources' => 'Укажите название источника.']);
            }
            if ($sortOrder === false || isset($keys[$sourceKey])) {
                throw ValidationException::withMessages(['sources' => 'Проверьте порядок и уникальность источников.']);
            }
            $rowAliases = [];
            foreach ((array) ($row['aliases'] ?? []) as $alias) {
                $value = is_array($alias) ? ($alias['value'] ?? '') : $alias;
                if (! is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ($value !== '' && mb_strlen($value) <= 120) {
                    $rowAliases[] = $value;
                }
            }
            $normalizedAliases = array_values(array_unique($rowAliases));
            foreach ($normalizedAliases as $alias) {
                $aliasKey = mb_strtolower($alias);
                if (isset($seenAliases[$aliasKey])) {
                    throw ValidationException::withMessages(['sources' => 'Синонимы источников должны быть уникальными.']);
                }
                $seenAliases[$aliasKey] = true;
            }
            $keys[$sourceKey] = true;
            $normalized[] = [
                'source_key' => $sourceKey,
                'label' => $label,
                'aliases' => $normalizedAliases,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => (int) $sortOrder,
            ];
        }

        return $normalized;
    }

    /** @return list<string> */
    private static function normalizeAliases(mixed $aliases): array
    {
        if (! is_array($aliases)) {
            return [];
        }

        $normalized = [];
        foreach ($aliases as $alias) {
            if (! is_string($alias)) {
                continue;
            }

            $value = trim($alias);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }
}
