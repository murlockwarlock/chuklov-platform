<?php

namespace Tests\Unit;

use App\Modules\MedicalProfiles\Application\MedicalPainInput;
use Tests\TestCase;

final class MedicalPainInputTest extends TestCase
{
    public function test_frequency_can_be_left_unspecified_without_becoming_permanent_and_zero_stays_zero(): void
    {
        $input = MedicalPainInput::fromArray([
            'name' => 'Шея',
            'score' => 0,
        ]);

        self::assertSame('unspecified', $input->frequencyMode);
        self::assertNull($input->frequencyCount);
        self::assertNull($input->frequencyPeriodUnit);
        self::assertSame(['all'], $input->timeOfDay);
        self::assertSame(0, $input->score);
    }

    public function test_blank_frequency_is_treated_as_unspecified_for_optional_rows(): void
    {
        $input = MedicalPainInput::fromArray([
            'before_score' => 5,
            'frequency_mode' => '',
        ]);

        self::assertSame('unspecified', $input->frequencyMode);
        self::assertNull($input->frequencyCount);
        self::assertNull($input->frequencyPeriodUnit);
    }

    public function test_fully_empty_repeater_row_is_detected_without_accepting_a_fake_pain(): void
    {
        self::assertTrue(MedicalPainInput::isEmptyRow([]));
        self::assertTrue(MedicalPainInput::isEmptyRow(['name' => '  ', 'frequency_mode' => 'unspecified', 'time_of_day' => ['all']]));
        self::assertFalse(MedicalPainInput::isEmptyRow(['name' => 'Шея', 'frequency_mode' => 'unspecified']));
    }

    public function test_partially_filled_row_preserves_entered_score_without_inventing_a_name(): void
    {
        $input = MedicalPainInput::fromArray([
            'before_score' => 5,
            'frequency_mode' => 'unspecified',
        ]);

        self::assertSame('', $input->name);
        self::assertSame(5, $input->beforeScore);
        self::assertNull($input->afterScore);
    }

    public function test_non_default_frequency_is_a_real_partial_value(): void
    {
        self::assertFalse(MedicalPainInput::isEmptyRow(['frequency_mode' => 'permanent']));
        self::assertFalse(MedicalPainInput::isEmptyRow([
            'frequency_mode' => 'count',
            'frequency_count' => 2,
            'frequency_period_unit' => 'day',
        ]));
    }
}
