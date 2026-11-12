<?php

namespace App\Modules\Specialists\Domain\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class SpecialistExperience
{
    private function __construct(public int $years) {}

    public static function fromYear(?int $year, ?CarbonImmutable $now = null): ?self
    {
        if ($year === null) {
            return null;
        }

        $years = max(0, ($now ?? CarbonImmutable::now('UTC'))->year - $year);

        return new self($years);
    }

    public function label(string $locale = 'ru'): string
    {
        if ($locale !== 'ru') {
            return $this->years.' '.($this->years === 1 ? 'year' : 'years').' of experience';
        }

        $lastTwo = $this->years % 100;
        $last = $this->years % 10;
        $unit = $lastTwo >= 11 && $lastTwo <= 14
            ? 'лет'
            : match (true) {
                $last === 1 => 'год',
                $last >= 2 && $last <= 4 => 'года',
                default => 'лет',
            };

        return $this->years.' '.$unit.' опыта';
    }
}
