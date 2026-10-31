<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class ClientAgeRangeCatalog
{
    /** @return list<array{key: string, label: string, min: int, max: int|null, active: bool, sort_order: int}> */
    public static function defaults(): array
    {
        return [
            ['key' => 'children', 'label' => 'Дети', 'min' => 0, 'max' => 17, 'active' => true, 'sort_order' => 10],
            ['key' => 'young_adults', 'label' => '18–29', 'min' => 18, 'max' => 29, 'active' => true, 'sort_order' => 20],
            ['key' => 'adults_30_44', 'label' => '30–44', 'min' => 30, 'max' => 44, 'active' => true, 'sort_order' => 30],
            ['key' => 'adults_45_59', 'label' => '45–59', 'min' => 45, 'max' => 59, 'active' => true, 'sort_order' => 40],
            ['key' => 'senior', 'label' => '60+', 'min' => 60, 'max' => null, 'active' => true, 'sort_order' => 50],
        ];
    }

    /** @return list<array{key: string, label: string, min: int, max: int|null, active: bool, sort_order: int}> */
    public function rows(int $organizationId, bool $activeOnly = false): array
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::AnalyticsAgeRanges->value)
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
            if (! is_array($row) || ! is_string($row['key'] ?? null) || ! is_string($row['label'] ?? null) || ! is_int($row['min'] ?? null)) {
                continue;
            }
            $max = $row['max'] ?? null;
            $active = $row['active'] ?? true;
            $sortOrder = $row['sort_order'] ?? ((int) $index + 1) * 10;
            if (($max !== null && ! is_int($max)) || ! is_bool($active) || ! is_int($sortOrder) || $sortOrder < 0) {
                continue;
            }
            $rows[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'min' => $row['min'],
                'max' => $max,
                'active' => $active,
                'sort_order' => $sortOrder,
            ];
        }

        $keys = array_column($rows, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return $this->filterRows(self::defaults(), $activeOnly);
        }

        if ($rows === []) {
            return $this->filterRows(self::defaults(), $activeOnly);
        }

        usort($rows, static fn (array $left, array $right): int => [$left['sort_order'], $left['key']] <=> [$right['sort_order'], $right['key']]);

        return $this->filterRows($rows, $activeOnly);
    }

    /** @return array<string, string> */
    public function options(int $organizationId): array
    {
        return array_column($this->rows($organizationId, true), 'label', 'key');
    }

    /** @return array{min: int, max: int|null}|null */
    public function range(int $organizationId, ?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }
        foreach ($this->rows($organizationId, true) as $row) {
            if ($row['key'] === $key) {
                return ['min' => $row['min'], 'max' => $row['max']];
            }
        }

        return null;
    }

    /** @param list<array{key: string, label: string, min: int, max: int|null, active: bool, sort_order: int}> $rows
     * @return list<array{key: string, label: string, min: int, max: int|null, active: bool, sort_order: int}>
     */
    private function filterRows(array $rows, bool $activeOnly): array
    {
        return $activeOnly ? array_values(array_filter($rows, static fn (array $row): bool => $row['active'])) : $rows;
    }
}
