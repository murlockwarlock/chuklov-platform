<?php

namespace Tests\Feature;

use App\Modules\Sessions\Application\DTOs\MedicalClinicalEvaluation;
use App\Modules\Sessions\Application\MedicalClinicalTypeEngine;
use App\Modules\Sessions\Application\MedicalStage1BCatalogPreset;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MedicalStage1BEngineTest extends TestCase
{
    public function test_preset_contains_all_32_tests_and_the_required_main_selection(): void
    {
        $definitions = MedicalStage1BCatalogPreset::definitions();

        self::assertCount(32, $definitions);
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
        self::assertSame('Orthopedic mobility probes', $definitions[31]['name']);
        self::assertSame(MedicalTestType::T13->value, $definitions[31]['type']);
        self::assertSame('specialized', $definitions[31]['catalog_set']);
        self::assertSame(
            ['32.1', '32.2', '32.3', '32.4', '32.5'],
            array_column($definitions[31]['configuration']['subtests'], 'key'),
        );
        self::assertCount(9, $definitions[14]['configuration']['subtests'][1]['rows']);
        self::assertSame(['Наклон вперёд', 'Наклон назад', 'Наклон вправо', 'Наклон влево'], array_column($definitions[24]['configuration']['subtests'][5]['rows'], 'label'));
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

    public function test_every_test_can_add_a_confirmed_general_index_to_row_evaluation(): void
    {
        $evaluation = (new MedicalClinicalTypeEngine)->evaluate(
            MedicalTestType::T1,
            ['rules' => ['index_violation' => [1, 2, 3]]],
            ['rows' => [['key' => 'shoulder', 'side' => 'R', 'index' => 0]]],
            ['rows' => [['key' => 'shoulder', 'side' => 'R', 'index' => 0]]],
            ['before' => 2, 'after' => 0],
        );

        self::assertSame(1, $evaluation->beforeCount);
        self::assertSame(0, $evaluation->afterCount);
        self::assertContains('general_index', $evaluation->beforeViolationKeys);
    }

    public function test_t13_keeps_measurements_and_uses_only_manual_indexes_without_norms(): void
    {
        $configuration = [
            'measurement_units' => ['cm', 'degree'],
            'movement_phases' => ['flexion', 'extension'],
            'answer_options' => ['yes', 'no'],
            'rules' => ['index_violation' => [1, 2, 3], 'classification_enabled' => false],
        ];

        $evaluation = (new MedicalClinicalTypeEngine)->evaluate(
            MedicalTestType::T13,
            $configuration,
            ['rows' => [[
                'key' => 'otto',
                'side' => 'R',
                'unit' => 'cm',
                'measurement' => 30,
                'movement_phase' => 'flexion',
                'answer' => 'yes',
                'index' => 2,
            ]]],
            ['rows' => [[
                'key' => 'otto',
                'side' => 'R',
                'unit' => 'cm',
                'measurement' => 30,
                'movement_phase' => 'extension',
                'answer' => 'yes',
                'index' => 0,
            ]]],
        );

        self::assertSame(1, $evaluation->beforeCount);
        self::assertSame(0, $evaluation->afterCount);
        self::assertFalse($evaluation->failClosed);
        self::assertSame([], $evaluation->warnings);
    }

    public function test_t13_rejects_an_invalid_measurement_unit_or_movement_phase(): void
    {
        $this->expectException(ValidationException::class);

        (new MedicalClinicalTypeEngine)->validate(
            MedicalTestType::T13,
            [
                'measurement_units' => ['cm'],
                'movement_phases' => ['flexion'],
                'rows' => [['key' => 'otto', 'unit_options' => ['cm'], 'phase_options' => ['flexion']]],
            ],
            ['rows' => [[
                'key' => 'otto',
                'unit' => 'degree',
                'measurement' => 10,
                'movement_phase' => 'extension',
            ]]],
            null,
        );
    }

    public function test_t2_and_t10_use_the_configured_index_rule(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $methodComputer = $engine->evaluate(
            MedicalTestType::T2,
            ['rules' => ['index_violation' => [2]]],
            ['index' => 1],
            ['index' => 2],
        );
        $visualMap = $engine->evaluate(
            MedicalTestType::T10,
            ['rules' => ['index_violation' => [3]]],
            ['rows' => [['key' => 'head', 'index' => 1]]],
            ['rows' => [['key' => 'head', 'index' => 3]]],
        );

        self::assertSame(0, $methodComputer->beforeCount);
        self::assertSame(1, $methodComputer->afterCount);
        self::assertSame(0, $visualMap->beforeCount);
        self::assertSame(1, $visualMap->afterCount);
    }

    public function test_t7_adds_the_configured_linked_segment_for_a_weak_side(): void
    {
        $evaluation = (new MedicalClinicalTypeEngine)->evaluate(
            MedicalTestType::T7,
            ['rules' => ['index_violation' => [1, 2, 3]]],
            ['rows' => [['key' => 'muscle_r', 'side' => 'R', 'linked_segment' => 'L5-S1', 'index' => 2]]],
            null,
        );

        self::assertSame(1, $evaluation->beforeCount);
        self::assertNotContains('linked_segment:L5-S1', $evaluation->beforeViolationKeys);
        self::assertSame(['L5-S1'], $evaluation->linkedSegments);
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

    public function test_t4_fails_closed_without_a_configured_formula_rule(): void
    {
        $evaluation = (new MedicalClinicalTypeEngine)->evaluate(
            MedicalTestType::T4,
            ['rules' => []],
            ['sequence' => ['L', 'L', 'R', 'L']],
            null,
        );

        self::assertTrue($evaluation->failClosed);
        self::assertSame(0, $evaluation->beforeCount);
        self::assertNotEmpty($evaluation->warnings);
    }

    public function test_t4_accepts_an_unstarted_blank_formula_without_classifying_it(): void
    {
        $engine = new MedicalClinicalTypeEngine;
        $configuration = [
            'rows' => [
                ['key' => 'zone_1', 'choices' => ['L', 'R']],
                ['key' => 'zone_2', 'choices' => ['L', 'R']],
                ['key' => 'zone_3', 'choices' => ['L', 'R']],
                ['key' => 'zone_4', 'choices' => ['L', 'R']],
            ],
            'rules' => [
                'allowed_sequences' => [['L', 'R', 'L', 'R']],
                'broken_transition_count' => true,
            ],
        ];
        $blank = ['sequence' => [
            ['value' => null],
            ['value' => null],
            ['value' => null],
            ['value' => null],
        ]];

        $engine->validate(MedicalTestType::T4, $configuration, $blank, null);
        $evaluation = $engine->evaluate(MedicalTestType::T4, $configuration, $blank, null);

        self::assertSame(0, $evaluation->beforeCount);
        self::assertFalse($evaluation->failClosed);
        self::assertSame([], $evaluation->warnings);
    }

    public function test_typed_validation_rejects_an_angle_outside_the_configured_range(): void
    {
        $this->expectException(ValidationException::class);

        (new MedicalClinicalTypeEngine)->validate(
            MedicalTestType::T9,
            ['rows' => [['key' => 'hip', 'label' => 'Тазобедренный сустав', 'input_mode' => 'mobility', 'angle_enabled' => true]]],
            ['rows' => [['key' => 'hip', 'angle' => 181, 'index' => 1]]],
            null,
        );
    }

    public function test_t12_does_not_classify_without_a_configured_threshold_or_normal_direction(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $evaluation = $engine->evaluate(
            MedicalTestType::T12,
            ['rules' => ['energy_threshold' => null, 'normal_tor_direction' => null]],
            [
                'energy' => 100,
                'tor_direction' => 'R',
            ],
            null,
        );

        self::assertTrue($evaluation->failClosed);
        self::assertSame(0, $evaluation->beforeCount);
        self::assertNotEmpty($evaluation->warnings);
    }

    public function test_t1_standing_and_lying_share_one_violation_identity(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $evaluation = $engine->evaluate(
            MedicalTestType::T1,
            [],
            ['rows' => [
                ['key' => 'standing_r', 'dedupe_key' => 'shoulder', 'side' => 'R', 'index' => 2],
                ['key' => 'lying_r', 'dedupe_key' => 'shoulder', 'side' => 'R', 'index' => 1],
            ]],
            null,
        );

        self::assertSame(1, $evaluation->beforeCount);
        self::assertSame(1, $evaluation->resolvedCount);
    }

    public function test_resolved_and_new_counts_use_violation_identity_sets(): void
    {
        $evaluation = new MedicalClinicalEvaluation(
            beforeViolationKeys: ['a', 'b'],
            afterViolationKeys: ['b', 'c'],
            beforeCount: 2,
            afterCount: 2,
        );

        self::assertSame(1, $evaluation->resolvedCount);
        self::assertSame(1, $evaluation->newCount);
    }

    public function test_t8_reports_the_configured_hypotone_safety_warning(): void
    {
        $engine = new MedicalClinicalTypeEngine;

        $evaluation = $engine->evaluate(
            MedicalTestType::T8,
            ['rules' => ['tone_violation' => ['hypotone']]],
            ['rows' => [['key' => 'neck', 'tone' => 'hypotone', 'index' => 1]]],
            null,
        );

        self::assertSame(['вытяжение в этом отделе не делать'], $evaluation->warnings);
        self::assertSame(1, $evaluation->beforeCount);
    }
}
