<?php

namespace App\Modules\Surveys\Application;

final class PlatformSurveyCatalog
{
    public const string HEALTH_KEY = 'platform_health_9_systems';

    public const string EXTENDED_SYMPTOMS_KEY = 'platform_extended_symptoms';

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $scale = [
            ['value' => 'never', 'label' => ['ru' => 'Никогда', 'en' => 'Never']],
            ['value' => 'rarely', 'label' => ['ru' => 'Редко', 'en' => 'Rarely']],
            ['value' => 'sometimes', 'label' => ['ru' => 'Иногда', 'en' => 'Sometimes']],
            ['value' => 'often', 'label' => ['ru' => 'Часто', 'en' => 'Often']],
            ['value' => 'almost_always', 'label' => ['ru' => 'Почти постоянно', 'en' => 'Almost constantly']],
        ];

        return [
            $this->buildSurvey(
                key: self::HEALTH_KEY,
                title: 'Скрининг здоровья: 9 систем',
                titleEn: 'Health screening: 9 systems',
                description: 'Демонстрационный опрос о самочувствии по девяти направлениям. Он помогает заметить зоны внимания, но не устанавливает диагнозы.',
                descriptionEn: 'A demonstration self-check across nine areas. It helps highlight areas to observe and does not establish diagnoses.',
                methodology: 'platform_default_9_systems',
                metricSchemaKey: 'platform-health-9-systems-v1',
                domains: $this->nineSystemsDomains(),
                scale: $scale,
            ),
            $this->buildSurvey(
                key: self::EXTENDED_SYMPTOMS_KEY,
                title: 'Расширенный опрос симптомов',
                titleEn: 'Extended symptom questionnaire',
                description: 'Демонстрационный расширенный опрос частоты и выраженности ощущений. Это не официальный MSQ или материал IFM.',
                descriptionEn: 'A demonstration questionnaire about symptom frequency and severity. It is not the official MSQ or IFM content.',
                methodology: 'platform_extended_symptom_questionnaire',
                metricSchemaKey: 'platform-extended-symptoms-v1',
                domains: $this->extendedSymptomDomains(),
                scale: $scale,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $domains
     * @param  list<array<string, mixed>>  $scale
     * @return array<string, mixed>
     */
    private function buildSurvey(
        string $key,
        string $title,
        string $titleEn,
        string $description,
        string $descriptionEn,
        string $methodology,
        string $metricSchemaKey,
        array $domains,
        array $scale,
    ): array {
        $sections = [];
        $metrics = [];
        $rules = [];
        $thresholds = [];
        $metricKeys = [];

        foreach ($domains as $domain) {
            $metricKey = (string) $domain['key'];
            $metricKeys[] = $metricKey;
            $questionKeys = [];
            $questions = [];

            foreach ($domain['items'] as $index => $item) {
                $questionKey = $metricKey.'_'.($index + 1);
                $questionKeys[] = $questionKey;
                $questions[] = [
                    'key' => $questionKey,
                    'type' => 'single_choice',
                    'label' => ['ru' => $item[0], 'en' => $item[1]],
                    'required' => true,
                    'options' => $scale,
                ];
                $rules[] = [
                    'question_key' => $questionKey,
                    'metric_key' => $metricKey,
                    'operator' => 'value_map',
                    'points' => ['never' => 0, 'rarely' => 1, 'sometimes' => 2, 'often' => 3, 'almost_always' => 4],
                ];
            }

            $metrics[] = [
                'key' => $metricKey,
                'label' => $domain['title'],
                'max_value' => count($domain['items']) * 4,
                'normalization' => 'symptom_burden_0_100',
                'question_keys' => $questionKeys,
                'attention_reason' => $domain['attention_reason'] ?? null,
                'observation' => $domain['observation'] ?? null,
                'road_map' => $domain['road_map'] ?? null,
            ];
            $thresholds[] = [
                'metric_key' => $metricKey,
                'max' => 6,
                'tag' => $metricKey.'_low',
                'label' => ['ru' => 'Низкая симптомная нагрузка', 'en' => 'Low symptom burden'],
            ];
            $thresholds[] = [
                'metric_key' => $metricKey,
                'min' => 7,
                'max' => 13,
                'tag' => $metricKey.'_attention',
                'label' => ['ru' => 'Стоит обратить внимание', 'en' => 'Worth observing'],
            ];
            $thresholds[] = [
                'metric_key' => $metricKey,
                'min' => 14,
                'tag' => $metricKey.'_pronounced',
                'label' => ['ru' => 'Выраженные жалобы', 'en' => 'Pronounced complaints'],
            ];
            $sections[] = [
                'key' => $metricKey,
                'title' => $domain['title'],
                'questions' => $questions,
            ];
        }

        return [
            'definition_key' => $key,
            'title' => $title,
            'title_en' => $titleEn,
            'description' => $description,
            'description_en' => $descriptionEn,
            'source' => 'platform_default',
            'approval_status' => 'draft',
            'methodology' => $methodology,
            'metric_schema_key' => $metricSchemaKey,
            'definition' => ['sections' => $sections],
            'scoring' => [
                'answer_scale' => ['never' => 0, 'rarely' => 1, 'sometimes' => 2, 'often' => 3, 'almost_always' => 4],
                'metrics' => $metrics,
                'rules' => $rules,
                'thresholds' => $thresholds,
                'comparison' => [
                    'operator' => 'no_decrease',
                    'metric_keys' => $metricKeys,
                    'basis' => 'normalized_score',
                ],
                'summary' => [
                    'ru' => 'Результат показывает, какие направления самочувствия сейчас стоит наблюдать внимательнее. Это не медицинское заключение.',
                    'en' => 'The result shows which areas of wellbeing may be worth observing more closely. It is not a medical conclusion.',
                ],
                'safe_steps' => [
                    ['ru' => 'Выберите одну небольшую привычку для наблюдения на ближайшую неделю.', 'en' => 'Choose one small habit to observe over the next week.'],
                    ['ru' => 'Отмечайте самочувствие и связь ощущений со сном, нагрузкой и режимом.', 'en' => 'Note how you feel and how it relates to sleep, activity, and routine.'],
                    ['ru' => 'Если жалобы сохраняются или усиливаются, обсудите их с лечащим специалистом.', 'en' => 'If complaints persist or worsen, discuss them with your treating specialist.'],
                ],
                'specialist_questions' => [
                    ['ru' => 'Какие из отмеченных симптомов важнее обсудить в первую очередь?', 'en' => 'Which of the noted symptoms should we discuss first?'],
                    ['ru' => 'Какие наблюдения или измерения помогут понять динамику?', 'en' => 'Which observations or measurements would help understand the trend?'],
                    ['ru' => 'Какие ограничения по нагрузке и восстановлению актуальны именно для меня?', 'en' => 'Which activity and recovery limits are relevant to me?'],
                ],
            ],
            'is_available' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function nineSystemsDomains(): array
    {
        return [
            ['key' => 'digestive', 'title' => ['ru' => 'Пищеварение и ЖКТ', 'en' => 'Digestion and gut'], 'items' => [
                ['Бывает ли вздутие или ощущение распирания?', 'Do you experience bloating or a feeling of fullness?'],
                ['Возникает ли дискомфорт после еды?', 'Do you feel discomfort after meals?'],
                ['Бывает ли стул нерегулярным или непривычным для вас?', 'Is your bowel pattern irregular or unusual for you?'],
                ['Возникает ли изжога или кислый привкус?', 'Do you experience heartburn or a sour taste?'],
                ['Бывает ли тошнота без очевидной причины?', 'Do you experience nausea without an obvious reason?'],
            ], 'attention_reason' => ['ru' => 'В ответах по пищеварению чаще встречались дискомфорт и изменения привычного самочувствия.', 'en' => 'Your answers more often mentioned digestive discomfort or changes from your usual state.'], 'observation' => ['ru' => 'Наблюдайте связь ощущений с приёмами пищи, режимом и стрессом.', 'en' => 'Observe how sensations relate to meals, routine, and stress.'], 'road_map' => ['ru' => 'Мягко упорядочить режим питания и вести короткие заметки о реакции самочувствия.', 'en' => 'Bring more regularity to meals and keep brief notes about how you feel.']],
            ['key' => 'sleep', 'title' => ['ru' => 'Сон и восстановление', 'en' => 'Sleep and recovery'], 'items' => [
                ['Трудно ли вам заснуть?', 'Is it difficult for you to fall asleep?'],
                ['Просыпаетесь ли вы ночью и долго не можете уснуть?', 'Do you wake at night and struggle to fall asleep again?'],
                ['Просыпаетесь ли вы раньше, чем хотелось бы?', 'Do you wake earlier than you would like?'],
                ['Чувствуете ли вы себя неотдохнувшим после сна?', 'Do you feel unrested after sleep?'],
                ['Бывает ли сонливость или усталость днём?', 'Do you feel sleepy or tired during the day?'],
            ], 'attention_reason' => ['ru' => 'В ответах заметны трудности с качеством сна или ощущением восстановления.', 'en' => 'Your answers point to difficulties with sleep quality or feeling restored.'], 'observation' => ['ru' => 'Отмечайте время сна, пробуждения и уровень энергии утром.', 'en' => 'Note sleep and wake times and your morning energy level.'], 'road_map' => ['ru' => 'Стабилизировать время подъёма и добавить спокойный переход ко сну.', 'en' => 'Keep a steadier wake time and create a calmer transition to sleep.']],
            ['key' => 'stress', 'title' => ['ru' => 'Нервная система и стресс', 'en' => 'Nervous system and stress'], 'items' => [
                ['Чувствуете ли вы постоянное внутреннее напряжение?', 'Do you feel ongoing inner tension?'],
                ['Возвращаются ли тревожные мысли, которые трудно остановить?', 'Do worrying thoughts return and feel hard to stop?'],
                ['Стали ли вы чаще раздражаться?', 'Have you been feeling irritable more often?'],
                ['Трудно ли сосредоточиться на обычных задачах?', 'Is it difficult to focus on ordinary tasks?'],
                ['Трудно ли вам расслабиться даже в спокойной обстановке?', 'Is it difficult to relax even in a calm setting?'],
            ], 'attention_reason' => ['ru' => 'В ответах чаще отмечались напряжение, тревожные мысли или трудности с концентрацией.', 'en' => 'Your answers more often mentioned tension, worry, or difficulty concentrating.'], 'observation' => ['ru' => 'Замечайте, что помогает снизить напряжение и как оно связано со сном и нагрузкой.', 'en' => 'Notice what reduces tension and how it relates to sleep and activity.'], 'road_map' => ['ru' => 'Добавить короткие паузы, дыхание или другую спокойную практику без форсирования.', 'en' => 'Add short pauses, breathing, or another gentle calming practice without forcing it.']],
            ['key' => 'musculoskeletal', 'title' => ['ru' => 'Опорно-двигательная система', 'en' => 'Musculoskeletal comfort'], 'items' => [
                ['Возникает ли дискомфорт в шее или спине?', 'Do you experience neck or back discomfort?'],
                ['Бывает ли скованность после сна или долгого сидения?', 'Do you feel stiff after sleep or prolonged sitting?'],
                ['Устают ли мышцы от привычной нагрузки быстрее обычного?', 'Do your muscles tire faster than usual during familiar activity?'],
                ['Ограничивает ли дискомфорт привычные движения?', 'Does discomfort limit your usual movements?'],
                ['Нужно ли вам долго восстанавливаться после физической нагрузки?', 'Do you need a long time to recover after physical activity?'],
            ], 'attention_reason' => ['ru' => 'В ответах чаще встречались дискомфорт, скованность или медленное восстановление после нагрузки.', 'en' => 'Your answers more often mentioned discomfort, stiffness, or slow recovery after activity.'], 'observation' => ['ru' => 'Сравнивайте реакцию на нагрузку, паузы и постепенное возвращение к движению.', 'en' => 'Compare how you respond to activity, pauses, and gradual return to movement.'], 'road_map' => ['ru' => 'Выбрать посильную регулярную активность и увеличивать её постепенно.', 'en' => 'Choose manageable regular activity and increase it gradually.']],
            ['key' => 'cardiovascular', 'title' => ['ru' => 'Сердечно-сосудистые проявления', 'en' => 'Cardiovascular sensations'], 'items' => [
                ['Замечаете ли вы сердцебиение в покое или при небольшой нагрузке?', 'Do you notice a racing heartbeat at rest or with light activity?'],
                ['Бывает ли головокружение при вставании или смене положения?', 'Do you feel dizzy when standing or changing position?'],
                ['Возникает ли необычная слабость при привычной нагрузке?', 'Do you experience unusual weakness during familiar activity?'],
                ['Бывает ли ощущение тяжести или дискомфорта в груди?', 'Do you feel heaviness or discomfort in your chest?'],
                ['Замечаете ли вы отёчность к концу дня?', 'Do you notice swelling by the end of the day?'],
            ], 'attention_reason' => ['ru' => 'В ответах отмечались ощущения, связанные с переносимостью нагрузки или кровообращением.', 'en' => 'Your answers mentioned sensations related to activity tolerance or circulation.'], 'observation' => ['ru' => 'Отмечайте обстоятельства, длительность и связь с нагрузкой; резкое ухудшение требует срочной помощи.', 'en' => 'Note the circumstances, duration, and relation to activity; sudden worsening requires urgent help.'], 'road_map' => ['ru' => 'Обсудить повторяющиеся или новые ощущения с врачом и не форсировать нагрузку.', 'en' => 'Discuss recurring or new sensations with a doctor and do not force activity.']],
            ['key' => 'respiratory', 'title' => ['ru' => 'Дыхательная система', 'en' => 'Breathing and respiratory comfort'], 'items' => [
                ['Бывает ли кашель, заложенность или раздражение в горле?', 'Do you experience cough, congestion, or throat irritation?'],
                ['Не хватает ли воздуха при привычной активности?', 'Do you feel short of breath during familiar activity?'],
                ['Приходится ли вам часто делать глубокий вдох?', 'Do you often feel the need to take a deep breath?'],
                ['Бывает ли ощущение поверхностного или напряжённого дыхания?', 'Does your breathing feel shallow or tense?'],
                ['Долго ли дыхание возвращается к обычному после нагрузки?', 'Does your breathing take a long time to return to normal after activity?'],
            ], 'attention_reason' => ['ru' => 'В ответах чаще встречались изменения дыхательного комфорта или восстановления после нагрузки.', 'en' => 'Your answers more often mentioned breathing comfort or recovery after activity.'], 'observation' => ['ru' => 'Наблюдайте дыхание в покое и при привычной нагрузке; внезапную одышку не ждите.', 'en' => 'Observe breathing at rest and during usual activity; do not wait with sudden shortness of breath.'], 'road_map' => ['ru' => 'Соблюдать постепенность нагрузки и обсудить устойчивые дыхательные жалобы со специалистом.', 'en' => 'Keep activity gradual and discuss persistent breathing complaints with a specialist.']],
            ['key' => 'energy', 'title' => ['ru' => 'Энергия и обменные проявления', 'en' => 'Energy and metabolic wellbeing'], 'items' => [
                ['Бывает ли заметный спад энергии в течение дня?', 'Do you experience a noticeable energy dip during the day?'],
                ['Меняется ли аппетит без понятной причины?', 'Does your appetite change without a clear reason?'],
                ['Тянет ли вас чаще к сладкому или быстрым перекусам?', 'Do you crave sweets or quick snacks more often?'],
                ['Сложно ли сохранять привычный уровень активности?', 'Is it difficult to maintain your usual level of activity?'],
                ['Долго ли вы восстанавливаетесь после обычного дня?', 'Do you take a long time to recover after an ordinary day?'],
            ], 'attention_reason' => ['ru' => 'В ответах заметны колебания энергии, аппетита или восстановления после обычного дня.', 'en' => 'Your answers point to changes in energy, appetite, or recovery after a usual day.'], 'observation' => ['ru' => 'Сопоставляйте энергию с режимом сна, питанием, нагрузкой и паузами.', 'en' => 'Relate energy to sleep, meals, activity, and breaks.'], 'road_map' => ['ru' => 'Планировать нагрузку с запасом восстановления и не пытаться компенсировать усталость рывком.', 'en' => 'Plan activity with recovery room and avoid compensating for fatigue with a sudden push.']],
            ['key' => 'immune', 'title' => ['ru' => 'Иммунные и воспалительные проявления', 'en' => 'Immune and inflammatory sensations'], 'items' => [
                ['Часто ли вы сталкиваетесь с простудными или похожими состояниями?', 'Do you often have colds or similar episodes?'],
                ['Долго ли вы восстанавливаетесь после недомогания?', 'Do you take a long time to recover after feeling unwell?'],
                ['Бывает ли длительное раздражение кожи или слизистых?', 'Do you experience prolonged irritation of the skin or mucous membranes?'],
                ['Возникают ли беспричинные ломота или ощущение разбитости?', 'Do you experience unexplained aches or feeling run down?'],
                ['Замечаете ли вы повторяющиеся эпизоды недомогания?', 'Do you notice recurring episodes of feeling unwell?'],
            ], 'attention_reason' => ['ru' => 'В ответах чаще отмечались повторяющееся недомогание или длительное восстановление.', 'en' => 'Your answers more often mentioned recurring discomfort or prolonged recovery.'], 'observation' => ['ru' => 'Записывайте длительность эпизодов и факторы, после которых они возникают.', 'en' => 'Record how long episodes last and what precedes them.'], 'road_map' => ['ru' => 'Поддерживать сон и восстановление, а повторяющиеся жалобы обсудить со специалистом.', 'en' => 'Support sleep and recovery, and discuss recurring complaints with a specialist.']],
            ['key' => 'urinary', 'title' => ['ru' => 'Водный баланс и мочевыделение', 'en' => 'Hydration and urinary wellbeing'], 'items' => [
                ['Чувствуете ли вы необычную жажду?', 'Do you feel unusually thirsty?'],
                ['Бывает ли сухость во рту или ощущение недостатка жидкости?', 'Do you experience dry mouth or a feeling of not having enough fluids?'],
                ['Часто ли вам приходится отвлекаться из-за позывов в туалет?', 'Do bathroom urges often interrupt your day?'],
                ['Просыпаетесь ли вы ночью из-за позыва в туалет?', 'Do you wake at night because you need the bathroom?'],
                ['Замечаете ли вы непривычные изменения в привычном водном режиме?', 'Do you notice unusual changes in your usual fluid routine?'],
            ], 'attention_reason' => ['ru' => 'В ответах отмечались изменения жажды, водного режима или привычных позывов.', 'en' => 'Your answers mentioned changes in thirst, fluid routine, or usual bathroom urges.'], 'observation' => ['ru' => 'Наблюдайте режим жидкости без крайностей и отмечайте устойчивые изменения.', 'en' => 'Observe your fluid routine without extremes and note persistent changes.'], 'road_map' => ['ru' => 'Поддерживать равномерный питьевой режим и обсуждать устойчивые изменения с врачом.', 'en' => 'Keep a steady fluid routine and discuss persistent changes with a doctor.']],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function extendedSymptomDomains(): array
    {
        return [
            ['key' => 'head', 'title' => ['ru' => 'Голова и органы чувств', 'en' => 'Head and senses'], 'items' => [
                ['Головная боль', 'Headache'], ['Ощущение давления в голове', 'A feeling of pressure in the head'], ['Шум или звон в ушах', 'Ringing or noise in the ears'], ['Напряжение глаз', 'Eye strain'], ['Чувствительность к яркому свету', 'Sensitivity to bright light'], ['Ощущение неустойчивости', 'A feeling of unsteadiness'],
            ]],
            ['key' => 'digestive_symptoms', 'title' => ['ru' => 'Пищеварение', 'en' => 'Digestion'], 'items' => [
                ['Вздутие живота', 'Abdominal bloating'], ['Тяжесть после еды', 'Heaviness after meals'], ['Изжога', 'Heartburn'], ['Тошнота', 'Nausea'], ['Изменение аппетита', 'Change in appetite'], ['Нерегулярный стул', 'Irregular bowel movements'],
            ]],
            ['key' => 'muscles', 'title' => ['ru' => 'Мышцы и движение', 'en' => 'Muscles and movement'], 'items' => [
                ['Дискомфорт в шее', 'Neck discomfort'], ['Дискомфорт в спине', 'Back discomfort'], ['Скованность суставов', 'Joint stiffness'], ['Мышечная усталость', 'Muscle fatigue'], ['Покалывание или онемение', 'Tingling or numbness'], ['Снижение привычной подвижности', 'Reduced usual mobility'],
            ]],
            ['key' => 'energy_symptoms', 'title' => ['ru' => 'Энергия и настроение', 'en' => 'Energy and mood'], 'items' => [
                ['Усталость', 'Fatigue'], ['Сонливость днём', 'Daytime sleepiness'], ['Трудно начать обычные дела', 'Difficulty starting usual tasks'], ['Раздражительность', 'Irritability'], ['Сниженное настроение', 'Low mood'], ['Трудно сосредоточиться', 'Difficulty concentrating'],
            ]],
            ['key' => 'sleep_symptoms', 'title' => ['ru' => 'Сон и стресс', 'en' => 'Sleep and stress'], 'items' => [
                ['Трудно заснуть', 'Difficulty falling asleep'], ['Пробуждения ночью', 'Waking during the night'], ['Неглубокий сон', 'Light sleep'], ['Тревожные мысли', 'Worrying thoughts'], ['Внутреннее напряжение', 'Inner tension'], ['Нет ощущения восстановления утром', 'Not feeling restored in the morning'],
            ]],
            ['key' => 'whole_body', 'title' => ['ru' => 'Общее самочувствие', 'en' => 'Overall wellbeing'], 'items' => [
                ['Ощущение разбитости', 'Feeling run down'], ['Озноб или зябкость', 'Chills or feeling cold'], ['Повышенная чувствительность к нагрузке', 'Increased sensitivity to activity'], ['Медленное восстановление', 'Slow recovery'], ['Необычная жажда', 'Unusual thirst'], ['Повторяющееся недомогание', 'Recurring feeling unwell'],
            ]],
        ];
    }
}
