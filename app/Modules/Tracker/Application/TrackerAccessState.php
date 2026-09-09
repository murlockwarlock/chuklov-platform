<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use Carbon\CarbonImmutable;

final readonly class TrackerAccessState
{
    public function __construct(
        public bool $enabled,
        public bool $freeMode,
        public ?TrackerEntitlement $entitlement,
    ) {}

    public function allowed(): bool
    {
        return $this->enabled && ($this->freeMode || $this->entitlement instanceof TrackerEntitlement);
    }

    public function statusLabel(): string
    {
        if (! $this->enabled) {
            return 'Трекер отключён';
        }

        if ($this->freeMode) {
            return 'Бесплатный режим';
        }

        $endsAt = $this->entitlement?->getRawOriginal('ends_at');
        if (is_string($endsAt) && $endsAt !== '') {
            return 'Активен до '.CarbonImmutable::parse($endsAt)->format('d.m.Y');
        }

        return 'Нет доступа';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed(),
            'enabled' => $this->enabled,
            'freeMode' => $this->freeMode,
            'statusLabel' => $this->statusLabel(),
            'planName' => $this->entitlement?->applied_plan_name,
            'startsAt' => $this->instant('starts_at'),
            'endsAt' => $this->instant('ends_at'),
        ];
    }

    private function instant(string $column): ?string
    {
        $value = $this->entitlement?->getRawOriginal($column);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
