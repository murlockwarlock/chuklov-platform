<?php

namespace Tests\Feature;

use App\Modules\Sessions\Application\MedicalStage1BCatalogPreset;
use App\Modules\Sessions\Application\MedicalClinicalTypeEngine;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;
use PHPUnit\Framework\TestCase;

final class MedicalStage1BEngineTest extends TestCase
{
    public function test_preset_contains_all_31_tests_and_the_required_main_selection(): void
    {
        $definitions = MedicalStage1BCatalogPreset::definitions();

        self::assertCount(31, $definitions);
        self::assertSame([
            'medical_test_001',
            'medical_test_002',
            'medical_test_003',
            'medical_test_004',
            'medical_test_005',
            'medical_test_006',
            'medical_test_007',
            'medical_test_009',
            'medical_test_025',
            'medical_test_031',
        ], MedicalStage1BCatalogPreset::defaultSelection());

        self::assertSame('Myotatic reflex, shoulder flexor', $definitions[0]['name']);
        self::assertSame(MedicalTestType::T1->value, $definitions[0]['type']);
        self::assertSame('Energy structures', $definitions[30]['name']);
        self::assertSame(MedicalTestType::T12->value, $definitions[30]['type']);
        self::assertSame(['31.1'], $definitions[30]['configuration']['default_subtests']);
    }

    public function test_registry_has_an_explicit_rule_for_each_type(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        foreach (MedicalTestType::cases() as $type) {
            self::assertTrue($engine->supports($type), $type->value);
        }
    }

    public function test_typed_rules_count_before_and_after_without_counting_empty_normal_values(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $evaluation = $engine->evaluate(
            MedicalTestType::T1,
            ['rules' => ['index_violation' => [1, 2, 3]]],
            ['rows' => [['key' => 'shoulder', 'side' => 'R', 'index' => 2]]],
            ['rows' => [['key' => 'shoulder', 'side' => 'R', 'index' => 0]]],
        );

        self::assertSame(1, $evaluation->beforeCount);
        self::assertSame(0, $evaluation->afterCount);
        self::assertSame(1, $evaluation->resolvedCount);
        self::assertFalse($evaluation->failClosed);
    }

    public function test_t4_counts_broken_transitions_using_the_catalog_rule(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $evaluation = $engine->evaluate(
            MedicalTestType::T4,
            [
                'rules' => [
                    'allowed_sequences' => [['L', 'R', 'L', 'R'], ['R', 'L', 'R', 'L']],
                    'broken_transition_count' => true,
                ],
            ],
            ['sequence' => ['L', 'L', 'R', 'L']],
            ['sequence' => ['L', 'R', 'L', 'R']],
        );

        self::assertSame(1, $evaluation->beforeCount);
        self::assertSame(0, $evaluation->afterCount);
        self::assertSame(['transition:1'], $evaluation->beforeViolationKeys);
    }

    public function test_t12_does_not_classify_without_a_configured_threshold_or_normal_direction(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $evaluation = $engine->evaluate(
            MedicalTestType::T12,
            ['rules' => ['energy_threshold' => null, 'normal_tor_direction' => null]],
            [
                'energy' => 100,
                'chakras' => [['key' => 'chakra_1', 'marked' => true]],
                'tor_direction' => 'R',
            ],
            null,
        );

        self::assertTrue($evaluation->failClosed);
        self::assertSame(0, $evaluation->beforeCount);
        self::assertNotEmpty($evaluation->warnings);
    }
}
