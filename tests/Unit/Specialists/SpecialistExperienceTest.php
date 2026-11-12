<?php

namespace Tests\Unit\Specialists;

use App\Modules\Specialists\Domain\ValueObjects\SpecialistExperience;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SpecialistExperienceTest extends TestCase
{
    public function test_russian_experience_pluralization_is_human_readable(): void
    {
        $now = CarbonImmutable::create(2026, 6, 1, 0, 0, 0, 'UTC');

        self::assertSame('1 год опыта', SpecialistExperience::fromYear(2025, $now)?->label('ru'));
        self::assertSame('2 года опыта', SpecialistExperience::fromYear(2024, $now)?->label('ru'));
        self::assertSame('5 лет опыта', SpecialistExperience::fromYear(2021, $now)?->label('ru'));
        self::assertSame('11 лет опыта', SpecialistExperience::fromYear(2015, $now)?->label('ru'));
        self::assertSame('21 год опыта', SpecialistExperience::fromYear(2005, $now)?->label('ru'));
    }

    public function test_missing_practice_year_has_no_experience_projection(): void
    {
        self::assertNull(SpecialistExperience::fromYear(null));
    }
}
