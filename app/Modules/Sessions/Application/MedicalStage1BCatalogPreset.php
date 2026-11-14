<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Enums\MedicalTestType;

final class MedicalStage1BCatalogPreset
{
    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::definition(1, 'Myotatic reflex, shoulder flexor', MedicalTestType::T1, 'main', [
                'rows' => self::testOneRows(),
                'source_cause_slots' => 21,
            ]),
            self::definition(2, 'Visual body and posture diagnosis', MedicalTestType::T10, 'main', [
                'rows' => [
                    ...self::rows(['Голова', 'Плечи', 'Лопатки', 'Таз', 'Колени', 'Стопы', 'Ось тела'], 'front'),
                    ...self::visualSideRows(),
                    ...self::rows(['Голова', 'Плечи', 'Лопатки', 'Позвоночник', 'Таз', 'Колени', 'Ахилловы сухожилия', 'Голень и стопа'], 'back'),
                ],
                'source_cause_slots' => 0,
            ]),
            self::definition(3, 'Shoulder standing test', MedicalTestType::T1, 'main', [
                'rows' => self::sideRows('Плечо, рука в сторону', 'shoulder'),
                'source_cause_slots' => 23,
            ]),
            self::definition(4, 'Scapula standing test', MedicalTestType::T1, 'main', [
                'rows' => self::sideRows('Лопатка, руки под 90 градусов', 'scapula'),
                'source_cause_slots' => 23,
            ]),
            self::definition(5, 'Gait pattern', MedicalTestType::T1, 'main', [
                'rows' => self::rows(['Правая нога + правая рука', 'Правая нога + левая рука', 'Левая нога + левая рука', 'Левая нога + правая рука'], 'gait'),
                'source_cause_slots' => 23,
            ]),
            self::definition(6, 'Push provocation tests', MedicalTestType::T1, 'main', [
                'rows' => self::sideRowsForLabels(['Основание черепа', 'Плечи', 'Лопатки', 'Диафрагма', 'Таз', 'Тазобедренный сустав'], 'push'),
                'tone_options' => ['normal', 'hypotone', 'hypertone'],
                'source_cause_slots' => 0,
            ]),
            self::definition(7, 'Body palpation', MedicalTestType::T3, 'main', [
                'rows' => [
                    ...self::rows(array_map(static fn (int $n): string => 'Шейный сегмент C'.$n, range(0, 8)), 'neck'),
                    ...self::rows(array_map(static fn (int $n): string => 'Грудной сегмент Th'.$n, range(1, 12)), 'thoracic'),
                    ...self::rows(array_map(static fn (int $n): string => 'Поясничный сегмент L'.$n, range(1, 5)), 'lumbar'),
                    ...self::sideRowsForLabels(array_map(static fn (int $n): string => 'Седалищный нерв, точка '.$n, range(1, 7)), 'sciatic'),
                    ...self::sideRowsForLabels(['ППМ'], 'ppm'),
                ],
                'finding_options' => ['shift_right', 'shift_left', 'spasm'],
                'source_cause_slots' => 0,
            ]),
            self::definition(8, 'Myozonal test', MedicalTestType::T4, 'specialized', [
                'rows' => self::rows(['Основание черепа', 'ШГП', 'ГПП', 'ПКП'], 'formula', ['choices' => ['L', 'R']]),
                'rules' => [
                    'allowed_sequences' => [['L', 'R', 'L', 'R'], ['R', 'L', 'R', 'L']],
                    'broken_transition_count' => true,
                ],
                'source_cause_slots' => 0,
            ]),
            self::definition(9, 'Global pain-point test', MedicalTestType::T5, 'main', [
                'subtests' => [
                    ['key' => '9.1', 'label' => 'Позиционные проверки', 'type' => MedicalTestType::T5->value, 'rows' => self::rows(['Стоя, R', 'Стоя, L', 'Сидя', 'Лёжа', 'Лёжа, рот открыт', 'Лёжа, зубы сжаты'], 'position')],
                    ['key' => '9.2', 'label' => 'Болевые зоны и провокации', 'type' => MedicalTestType::T6->value, 'rows' => self::rows(array_map(static fn (int $n): string => 'Зона '.$n, range(1, 12)), 'region'), 'provoking_points' => self::rows(array_map(static fn (int $n): string => 'Провоцирующая точка '.$n, range(1, 14)), 'point')],
                ],
                'movement_options' => ['standing', 'bending', 'rising_from_chair', 'walking', 'rest'],
                'source_cause_slots' => 0,
            ]),
            self::definition(10, 'Sitting traction test', MedicalTestType::T8, 'specialized', [
                'rows' => self::rows(['ШОП', 'ГОП', 'ПОП'], 'department'),
                'rules' => ['tone_violation' => ['hypotone', 'гипотонус'], 'warning_on_hypotone' => true],
                'source_cause_slots' => 0,
            ]),
            self::definition(11, 'Neck method computer', MedicalTestType::T2, 'specialized', ['source_cause_slots' => 25]),
            self::definition(12, 'Lumbar method computer', MedicalTestType::T2, 'specialized', ['source_cause_slots' => 27]),
            self::definition(13, 'Pelvis method computer', MedicalTestType::T2, 'specialized', ['source_cause_slots' => 26]),
            self::definition(14, 'Sacrum method computer', MedicalTestType::T2, 'specialized', ['source_cause_slots' => 17]),
            self::definition(15, 'Body diaphragms', MedicalTestType::T1, 'specialized', [
                'subtests' => [
                    ['key' => '15.1', 'label' => 'Тазовая диафрагма', 'rows' => self::sideRows('Тазовая диафрагма', 'pelvic')],
                    ['key' => '15.2', 'label' => 'ГБД и ноги', 'rows' => self::rows(['ГБД, L', 'ГБД, R', 'Ноги'], 'abdomen'), 'method_options' => ['A', 'B', 'C']],
                    ['key' => '15.3', 'label' => 'Грудная диафрагма', 'rows' => self::sideRows('Грудная диафрагма', 'thoracic')],
                ],
                'source_cause_slots' => 0,
            ]),
            self::definition(16, 'Lumbar/sacral nerve compression', MedicalTestType::T7, 'specialized', [
                'rows' => self::sideRowsForLabels(['L1-L2', 'L2-L4', 'L5-S1', 'L5', 'S1-S2'], 'muscle'),
                'rules' => ['index_violation' => [1, 2, 3], 'zero_is_normal' => true],
                'source_cause_slots' => 0,
            ]),
            self::definition(17, 'Neck nerve compression', MedicalTestType::T7, 'specialized', [
                'rows' => self::sideRowsForLabels(['C1-C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'Th1'], 'muscle'),
                'rules' => ['index_violation' => [1, 2, 3], 'zero_is_normal' => true],
                'source_cause_slots' => 0,
            ]),
            self::definition(18, 'Cervical spinal-cord compression', MedicalTestType::T5, 'specialized', [
                'rows' => [
                    ...self::sideRowsForLabels(['Квадрицепс', 'Трицепс'], 'sitting'),
                    ...self::rows(['Подбородок к шее'], 'lying'),
                ],
                'safety_marker_text' => 'Жёсткие манипуляции, особенно с шеей, запрещены',
                'source_cause_slots' => 0,
            ]),
            self::definition(19, 'Dura-mater twisting', MedicalTestType::T2, 'specialized', [
                'subtests' => [
                    ['key' => '19.1', 'label' => 'Метод-компьютер', 'type' => MedicalTestType::T2->value, 'source_cause_slots' => 10],
                    ['key' => '19.2', 'label' => 'Скручивание грудной клетки', 'type' => MedicalTestType::T5->value, 'rows' => self::rows(['Вправо', 'Влево'], 'twist')],
                    ['key' => '19.3', 'label' => 'Неврологические тесты', 'type' => MedicalTestType::T5->value, 'rows' => self::rows(['Тест 1', 'Тест 2', 'Тест 3', 'Тест 4'], 'neurology')],
                ],
                'violation_types' => ['механическое', 'информационное'],
                'source_cause_slots' => 0,
            ]),
            self::definition(20, 'Myofascial chains', MedicalTestType::T11, 'specialized', [
                'rows' => self::rows(['Глубокая', 'Латеральная', 'Передняя', 'Задняя', 'Спиральная'], 'chain'),
                'priority_options' => ['deep', 'lateral', 'front', 'back', 'spiral'],
                'source_cause_slots' => 0,
            ]),
            self::definition(21, 'TMJ method computer', MedicalTestType::T2, 'specialized', ['source_cause_slots' => 12]),
            self::definition(22, 'Skull method computer', MedicalTestType::T2, 'specialized', [
                'rows' => array_map(static fn (array $row): array => [...$row, 'input_mode' => 'finding_list', 'index_enabled' => false], self::rows(['Лобная кость', 'Теменные кости', 'Височные кости', 'Затылочная кость', 'Клиновидная кость', 'Решётчатая кость', 'Носовые кости', 'Скуловые кости'], 'bone')),
                'index_enabled' => false,
                'source_cause_slots' => 4,
            ]),
            self::definition(23, 'Abdominal muscles', MedicalTestType::T1, 'specialized', [
                'rows' => [
                    ...self::rows(['Прямая мышца живота'], 'rectus'),
                    ...self::sideRowsForLabels(['Наружная косая мышца'], 'external_oblique'),
                    ...self::sideRowsForLabels(['Внутренняя косая мышца'], 'internal_oblique'),
                ],
                'source_cause_slots' => 0,
            ]),
            self::definition(24, 'Pelvic muscles', MedicalTestType::T1, 'specialized', [
                'rows' => [
                    ...self::sideRowsForLabels(['Большая ягодичная мышца'], 'gluteus'),
                    ...self::sideRowsForLabels(['Тазовая диафрагма, задний пучок', 'Тазовая диафрагма, средний пучок', 'Тазовая диафрагма, передний пучок'], 'pelvic_diaphragm'),
                    ...self::sideRowsForLabels(['Грушевидная мышца'], 'piriformis'),
                    ...self::sideRowsForLabels(['Близнецовые мышцы'], 'gemelli'),
                ],
                'source_cause_slots' => 0,
            ]),
            self::definition(25, 'Biomechanical mobility tests', MedicalTestType::T9, 'main', [
                'subtests' => [
                    ['key' => '25.1', 'label' => 'Колени', 'type' => MedicalTestType::T9->value, 'rows' => self::sideRows('Колени', 'knees')],
                    ['key' => '25.2', 'label' => 'Тазобедренные суставы', 'type' => MedicalTestType::T9->value, 'rows' => self::sideRows('Тазобедренные суставы', 'hips')],
                    ['key' => '25.3', 'label' => 'Плечи', 'type' => MedicalTestType::T9->value, 'rows' => self::sideRows('Плечи', 'shoulders')],
                    ['key' => '25.4', 'label' => 'Локти', 'type' => MedicalTestType::T9->value, 'rows' => self::sideRows('Локти', 'elbows')],
                    ['key' => '25.5', 'label' => 'Лопатки', 'type' => MedicalTestType::T9->value, 'rows' => self::sideRows('Лопатки', 'scapulae')],
                    ['key' => '25.6', 'label' => 'Наклон поясницы', 'type' => MedicalTestType::T9->value, 'rows' => self::rows(['Наклон поясницы'], 'lumbar_bend', ['angle_enabled' => true])],
                    ['key' => '25.7', 'label' => 'Голеностоп', 'type' => MedicalTestType::T9->value, 'rows' => array_map(static fn (array $row): array => [...$row, 'angle_enabled' => false], self::sideRows('Голеностоп', 'ankle'))],
                ],
                'rules' => ['angle_min' => 0, 'angle_max' => 180, 'ankle_sides' => true, 'forward_bend_without_side' => true],
                'source_cause_slots' => 0,
            ]),
            self::definition(26, 'Vagus nerve', MedicalTestType::T11, 'specialized', [
                'rows' => self::rows(array_map(static fn (int $n): string => 'Локация '.$n, range(1, 18)), 'vagus'),
                'source_cause_slots' => 0,
            ]),
            self::definition(27, 'Phrenic nerve', MedicalTestType::T11, 'specialized', [
                'rows' => self::rows(array_map(static fn (int $n): string => 'Локация '.$n, range(1, 9)), 'phrenic'),
                'source_cause_slots' => 0,
            ]),
            self::definition(28, 'Internal organs', MedicalTestType::T11, 'specialized', [
                'rows' => self::rows(array_map(static fn (int $n): string => 'Орган '.$n, range(1, 34)), 'organ'),
                'source_cause_slots' => 10,
            ]),
            self::definition(29, 'Psychosomatics', MedicalTestType::T11, 'specialized', [
                'rows' => self::rows(array_map(static fn (int $n): string => 'Позиция '.$n, range(1, 9)), 'psychosomatic'),
                'feelings' => self::rows(array_map(static fn (int $n): string => 'Чувство '.$n, range(1, 16)), 'feeling'),
                'source_cause_slots' => 0,
            ]),
            self::definition(30, 'Thin plane', MedicalTestType::T11, 'specialized', [
                'rows' => self::rows(array_map(static fn (int $n): string => 'Позиция '.$n, range(1, 11)), 'thin_plane'),
                'source_cause_slots' => 0,
            ]),
            self::definition(31, 'Energy structures', MedicalTestType::T12, 'main', [
                'default_subtests' => ['31.1'],
                'subtests' => [
                    ['key' => '31.1', 'label' => 'Энергетический потенциал', 'type' => MedicalTestType::T12->value, 'range' => ['min' => 0, 'max' => 1000]],
                    ['key' => '31.2', 'label' => 'Чакры', 'type' => MedicalTestType::T12->value, 'rows' => self::rows(array_map(static fn (int $n): string => 'Чакра '.$n, range(1, 7)), 'chakra')],
                    ['key' => '31.3', 'label' => 'Тор-поле', 'type' => MedicalTestType::T12->value, 'choices' => ['R', 'L']],
                ],
                'rules' => ['energy_threshold' => null, 'normal_tor_direction' => null, 'chakra_violation' => true],
                'source_cause_slots' => 5,
            ]),
        ];
    }

    /** @return list<string> */
    public static function defaultSelection(): array
    {
        return [
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
        ];
    }

    /** @param array<string, mixed> $configuration */
    private static function definition(int $number, string $name, MedicalTestType $type, string $set, array $configuration): array
    {
        $configuration = [
            'version' => 1,
            'rules' => $configuration['rules'] ?? [],
            ...$configuration,
        ];

        return [
            'key' => sprintf('medical_test_%03d', $number),
            'name' => $name,
            'section' => $set === 'main' ? 'Основные тесты' : 'Специализированные тесты',
            'type' => $type->value,
            'set' => $set,
            'is_default' => in_array(sprintf('medical_test_%03d', $number), self::defaultSelection(), true),
            'sort_order' => $number,
            'configuration' => $configuration,
            'values' => [
                ['key' => 'normal', 'label' => 'Норма', 'is_abnormal' => false, 'is_active' => true, 'sort_order' => 0],
                ['key' => 'violation', 'label' => 'Нарушение', 'is_abnormal' => true, 'is_active' => true, 'sort_order' => 1],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function testOneRows(): array
    {
        return [
            ['key' => 'standing_r', 'label' => 'Стоя, правая сторона', 'side' => 'R', 'dedupe_key' => 'shoulder:R'],
            ['key' => 'standing_l', 'label' => 'Стоя, левая сторона', 'side' => 'L', 'dedupe_key' => 'shoulder:L'],
            ['key' => 'lying_r', 'label' => 'Лёжа, правая сторона', 'side' => 'R', 'dedupe_key' => 'shoulder:R'],
            ['key' => 'lying_l', 'label' => 'Лёжа, левая сторона', 'side' => 'L', 'dedupe_key' => 'shoulder:L'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function visualSideRows(): array
    {
        $rows = self::rows(['Общий гравитационный вектор', 'Голова и шея', 'Грудная клетка', 'Таз', 'Колени', 'Стопы'], 'side');
        $rows[0]['index_enabled'] = false;

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function sideRows(string $label, string $key): array
    {
        return self::sideRowsForLabels([$label], $key);
    }

    /** @param list<string> $labels
     * @return list<array<string, mixed>>
     */
    private static function sideRowsForLabels(array $labels, string $key, array $extra = []): array
    {
        $rows = [];
        foreach ($labels as $label) {
            foreach (['R' => 'правая сторона', 'L' => 'левая сторона'] as $side => $sideLabel) {
                $rows[] = [
                    'key' => $key.'_'.strtolower($side).'_'.count($rows),
                    'label' => $label.', '.$sideLabel,
                    'side' => $side,
                    ...$extra,
                ];
            }
        }

        return $rows;
    }

    /** @param list<string> $labels
     * @return list<array<string, mixed>>
     */
    private static function rows(array $labels, string $prefix, array $extra = []): array
    {
        return array_values(array_map(
            static fn (string $label, int $index): array => [
                'key' => $prefix.'_'.$index,
                'label' => $label,
                ...$extra,
            ],
            $labels,
            array_keys($labels),
        ));
    }
}
