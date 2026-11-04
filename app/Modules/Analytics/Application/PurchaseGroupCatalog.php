<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class PurchaseGroupCatalog
{
    /** @return list<array{key: string, label: string, catalog_types: list<string>, active: bool, sort_order: int}> */
    public static function defaults(): array
    {
        return [
            ['key' => 'service', 'label' => 'Разовые сеансы', 'catalog_types' => ['service'], 'active' => true, 'sort_order' => 10],
            ['key' => 'program', 'label' => 'Пакеты и программы', 'catalog_types' => ['program'], 'active' => true, 'sort_order' => 20],
            ['key' => 'gift_certificate', 'label' => 'Сертификаты', 'catalog_types' => ['gift_certificate'], 'active' => true, 'sort_order' => 30],
            ['key' => 'physical_product', 'label' => 'Товары', 'catalog_types' => ['physical_product'], 'active' => true, 'sort_order' => 40],
            ['key' => 'online_product', 'label' => 'Онлайн-продукты', 'catalog_types' => ['online_product'], 'active' => true, 'sort_order' => 50],
            ['key' => 'unknown', 'label' => 'Другие покупки', 'catalog_types' => ['unknown'], 'active' => true, 'sort_order' => 60],
        ];
    }

    /** @return list<array{key: string, label: string, catalog_types: list<string>, active: bool, sort_order: int}> */
    public function rows(int $organizationId, bool $activeOnly = false): array
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::AnalyticsPurchaseGroups->value)
            ->first();
        if ($setting === null || ! is_string($setting->string_value)) {
            return $this->filterRows(self::defaults(), $activeOnly);
        }

        $decoded = json_decode($setting->string_value, true);
        if (! is_array($decoded)) {
            return $this->filterRows(self::defaults(), $activeOnly);
        }

        $rows = [];
        foreach ($decoded as $index => $row) {
            $normalized = $this->normalizeRow($row, (int) $index);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }
        $keys = array_column($rows, 'key');
        $types = [];
        $activeTypes = [];
        foreach ($rows as $row) {
            $types = [...$types, ...$row['catalog_types']];
            if ($row['active']) {
                $activeTypes = [...$activeTypes, ...$row['catalog_types']];
            }
        }
        if ($rows === [] || count($keys) !== count(array_unique($keys)) || count($activeTypes) !== count(array_unique($activeTypes))) {
            return $this->filterRows(self::defaults(), $activeOnly);
        }

        $unknownMapped = in_array('unknown', $types, true);
        if (! $unknownMapped) {
            $rows[] = ['key' => 'unknown', 'label' => 'Другие покупки', 'catalog_types' => ['unknown'], 'active' => true, 'sort_order' => count($rows) * 10 + 10];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['sort_order'], $left['key']] <=> [$right['sort_order'], $right['key']]);

        return $this->filterRows($rows, $activeOnly);
    }

    /** @return array{key: string, label: string, catalog_types: list<string>, active: bool, sort_order: int}|null */
    private function normalizeRow(mixed $row, int $index): ?array
    {
        if (! is_array($row) || ! is_string($row['key'] ?? null) || ! is_string($row['label'] ?? null) || ! is_array($row['catalog_types'] ?? null)) {
            return null;
        }

        $active = $row['active'] ?? true;
        $sortOrder = $row['sort_order'] ?? (($index + 1) * 10);
        if (! is_bool($active) || ! is_int($sortOrder) || $sortOrder < 0) {
            return null;
        }
        $types = array_values(array_unique(array_filter(
            array_map(static fn (mixed $type): string => trim((string) $type), $row['catalog_types']),
            static fn (string $type): bool => $type !== '',
        )));
        if ($types === []) {
            return null;
        }

        return [
            'key' => $row['key'],
            'label' => $row['label'],
            'catalog_types' => $types,
            'active' => $active,
            'sort_order' => $sortOrder,
        ];
    }

    /** @return array<string, string> */
    public function options(int $organizationId, bool $activeOnly = true): array
    {
        return array_column($this->rows($organizationId, $activeOnly), 'label', 'key');
    }

    /** @param list<string> $keys
     * @return list<string>
     */
    public function catalogTypesFor(int $organizationId, array $keys, bool $activeOnly = true): array
    {
        $types = [];
        foreach ($this->rows($organizationId, $activeOnly) as $row) {
            if (in_array($row['key'], $keys, true)) {
                $types = [...$types, ...$row['catalog_types']];
            }
        }

        return array_values(array_unique($types === [] ? $keys : $types));
    }

    public function keyForCatalogType(int $organizationId, string $catalogType): string
    {
        foreach ($this->rows($organizationId, false) as $row) {
            if (in_array($catalogType, $row['catalog_types'], true)) {
                return $row['key'];
            }
        }

        return 'unknown';
    }

    /** @param list<array{key: string, label: string, catalog_types: list<string>, active: bool, sort_order: int}> $rows
     * @return list<array{key: string, label: string, catalog_types: list<string>, active: bool, sort_order: int}>
     */
    private function filterRows(array $rows, bool $activeOnly): array
    {
        return $activeOnly ? array_values(array_filter($rows, static fn (array $row): bool => $row['active'])) : $rows;
    }
}
