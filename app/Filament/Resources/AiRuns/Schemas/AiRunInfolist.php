<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetAiRunProtectedTrace;
use App\Modules\AI\Application\Data\AiRunProtectedTraceData;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class AiRunInfolist
{
    /** @var \WeakMap<AiRun, AiRunProtectedTraceData|null>|null */
    private static ?\WeakMap $traceCache = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Сводка запуска')
                    ->schema([
                        TextEntry::make('capability')
                            ->label('Возможность')
                            ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? $state->label() : (string) $state),
                        TextEntry::make('origin')
                            ->label('Источник')
                            ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->wrap()
                            ->color(fn ($state): string => match ($state instanceof AiRunStatus ? $state->value : (string) $state) {
                                'succeeded' => 'success',
                                'running' => 'info',
                                'queued' => 'gray',
                                'invalid_output' => 'warning',
                                'failed', 'timed_out' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => $state instanceof AiRunStatus ? $state->label() : (string) $state),
                        TextEntry::make('actual_provider')->label('Провайдер')->placeholder('—'),
                        TextEntry::make('actual_model')->label('Модель')->placeholder('—'),
                        TextEntry::make('latency_ms')
                            ->label('Время выполнения')
                            ->formatStateUsing(fn ($state) => $state ? ($state > 1000 ? round($state / 1000, 2).' с' : $state.' мс') : '—'),
                        TextEntry::make('settled_estimated_cost_minor_units')
                            ->label('Оценочная стоимость')
                            ->formatStateUsing(fn ($state) => $state !== null ? '$'.number_format($state / 10000, 4) : '—'),
                        TextEntry::make('human_review_status')
                            ->label('Статус проверки')
                            ->badge()
                            ->wrap()
                            ->formatStateUsing(fn ($state) => $state instanceof HumanReviewStatus ? $state->label() : (string) $state),
                    ])
                    ->columns(3),

                Section::make('Технические данные')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('id')->label('ID запуска')->fontFamily('mono'),
                        TextEntry::make('prompt_version_id')->label('ID версии промпта')->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('model_release_id')->label('ID релиза модели')->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('rendered_prompt_digest')->label('Хеш промпта')->fontFamily('mono')->placeholder('—')->wrap(),
                        TextEntry::make('context_hash')
                            ->label('Хеш контекста')
                            ->state(fn (AiRun $record): string => self::contextHash($record))
                            ->fontFamily('mono'),
                        TextEntry::make('technical_input_references')
                            ->label('Ссылки на источники')
                            ->state(fn (AiRun $record): string => self::pretty($record->input_references))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(3),

                Section::make('Ошибки и попытки выполнения')
                    ->schema([
                        TextEntry::make('error_message_sanitized')
                            ->label('Сообщение об ошибке')
                            ->placeholder('Ошибок нет')
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('attempts_summary')
                            ->label('История попыток')
                            ->state(function (AiRun $record): string {
                                $attempts = $record->attempts()->orderBy('attempt_number')->get();
                                if ($attempts->isEmpty()) {
                                    return 'Нет записей о попытках.';
                                }
                                $lines = [];
                                foreach ($attempts as $att) {
                                    $rev = $att->credential_revision ? substr($att->credential_revision, 0, 8).'…' : '—';
                                    $cost = '$'.number_format($att->settled_estimated_cost_minor_units / 10000, 4);
                                    $lines[] = "#{$att->attempt_number} · {$att->provider}/{$att->model} · Статус: {$att->status} · {$att->latency_ms}мс · Стоимость: {$cost} · Ревизия ключа: {$rev}";
                                }

                                return implode("\n", $lines);
                            })
                            ->columnSpanFull()
                            ->wrap(),
                    ]),

                Section::make('База знаний (RAG)')
                    ->schema([
                        TextEntry::make('rag_summary')
                            ->label('Использованные фрагменты')
                            ->state(function (AiRun $record): string {
                                $refs = $record->ragReferences()->orderBy('reference_index')->get();
                                if ($refs->isEmpty()) {
                                    return 'База знаний не использовалась.';
                                }
                                $lines = [];
                                foreach ($refs as $ref) {
                                    $lines[] = "#{$ref->reference_index} · Источник #{$ref->knowledge_source_id} (Фрагмент #{$ref->knowledge_chunk_id}) · Сходство: ".round($ref->similarity_score * 100, 1).'%';
                                }

                                return implode("\n", $lines);
                            })
                            ->columnSpanFull()
                            ->wrap(),
                    ]),

                Section::make('Защищённый след (Protected Trace)')
                    ->schema([
                        TextEntry::make('protected_trace_request')
                            ->label('Запрос / ввод')
                            ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->userPrompt ?: 'Ввод отсутствует.'))
                            ->columnSpanFull()
                            ->wrap()
                            ->markdown(),
                        TextEntry::make('protected_trace_sources')
                            ->label('Источники ввода')
                            ->state(fn (AiRun $record): string => self::traceText($record, [self::class, 'sourceText']))
                            ->columnSpanFull()
                            ->wrap()
                            ->markdown(),
                        Section::make('Промпт')
                            ->schema([
                                TextEntry::make('protected_trace_prompt_name')
                                    ->label('Промпт и версия')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => trim(($trace->promptName ?? '—').' · версия '.($trace->promptVersion ?? '—')))),
                                TextEntry::make('protected_trace_source_prompt')
                                    ->label('Исходный текст промпта')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->sourcePrompt ?: 'Исходный текст недоступен.'))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                                TextEntry::make('protected_trace_guardrails')
                                    ->label('Платформенные защитные правила')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->platformSafetyGuardrails ?: 'Отдельные правила не записаны.'))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                            ])
                            ->columns(2),
                        Section::make('Модель и контекст')
                            ->schema([
                                TextEntry::make('protected_trace_model')
                                    ->label('Модель')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => self::pretty($trace->model)))
                                    ->wrap(),
                                TextEntry::make('protected_trace_context')
                                    ->label('Снимок контекста')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => self::pretty($trace->contextProvenance)))
                                    ->wrap(),
                                TextEntry::make('protected_trace_rag')
                                    ->label('Источники базы знаний')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->ragReferences === [] ? 'База знаний не использовалась.' : self::pretty($trace->ragReferences)))
                                    ->columnSpanFull()
                                    ->wrap(),
                            ])
                            ->columns(2),
                        Section::make('Результат')
                            ->schema([
                                TextEntry::make('protected_trace_output')
                                    ->label('Результат для специалиста')
                                    ->state(fn (AiRun $record): string => self::traceText($record, [self::class, 'outputText']))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                                TextEntry::make('protected_trace_review')
                                    ->label('Проверка специалиста')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => implode("\n\n", array_filter([
                                        $trace->humanReviewNotes !== null ? 'Заметки: '.$trace->humanReviewNotes : null,
                                        $trace->humanEditedOutput !== null ? 'Исправленный результат: '.$trace->humanEditedOutput : null,
                                    ])) ?: 'Дополнительных заметок нет.'))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                            ]),
                        Section::make('Показать исходный JSON')
                            ->collapsed()
                            ->schema([
                                TextEntry::make('protected_trace_raw_json')
                                    ->label('Исходный JSON')
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => self::pretty($trace->outputPayload)))
                                    ->columnSpanFull()
                                    ->wrap(),
                            ]),
                    ]),
            ]);
    }

    private static function trace(AiRun $record): ?AiRunProtectedTraceData
    {
        $cache = self::$traceCache ??= new \WeakMap;
        if ($cache->offsetExists($record)) {
            return $cache[$record];
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            $cache[$record] = null;

            return null;
        }

        try {
            $trace = app(GetAiRunProtectedTrace::class)->handle($user, (int) $record->getKey());
            $cache[$record] = $trace;

            return $trace;
        } catch (\Throwable) {
            $cache[$record] = null;

            return null;
        }
    }

    private static function contextHash(AiRun $record): string
    {
        return hash('sha256', json_encode([
            $record->context_provenance,
            $record->input_references,
            $record->ragReferences()
                ->get()
                ->map(static fn ($reference): array => [
                    'index' => $reference->reference_index,
                    'source_id' => $reference->knowledge_source_id,
                    'revision_id' => $reference->knowledge_revision_id,
                    'chunk_id' => $reference->knowledge_chunk_id,
                ])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param callable(AiRunProtectedTraceData): string $resolver */
    private static function traceText(AiRun $record, callable $resolver): string
    {
        $trace = self::trace($record);
        if ($trace === null) {
            return 'Доступ к защищённому следу ограничен политикой безопасности.';
        }

        return $resolver($trace);
    }

    private static function sourceText(AiRunProtectedTraceData $trace): string
    {
        $labels = [
            'client' => 'Профиль клиента',
            'medical_attachment' => 'Медицинское вложение',
            'companion_attachment' => 'Вложение диалога',
            'medical_session' => 'Медицинский сеанс',
            'survey_attempt' => 'Результат опроса',
            'booking' => 'Запись на приём',
            'ai_run' => 'Предыдущий AI-анализ',
            'knowledge_source' => 'Источник базы знаний',
        ];
        $references = collect($trace->inputReferences)
            ->map(static function (array $reference) use ($labels): string {
                $type = (string) ($reference['type'] ?? 'Источник');
                $label = $labels[$type] ?? $type;
                $role = isset($reference['role']) ? ' · '.(string) $reference['role'] : '';
                $details = collect([
                    $reference['name'] ?? null,
                    $reference['filename'] ?? null,
                    $reference['title'] ?? null,
                ])->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')->implode(' · ');

                return $label.$role.($details === '' ? '' : ': '.$details);
            })
            ->implode("\n");

        return implode("\n\n", array_filter([
            $references !== '' ? $references : 'Явные ссылки на источники не записаны.',
            'Извлечённый контекст: '.self::pretty($trace->contextProvenance),
        ]));
    }

    private static function outputText(AiRunProtectedTraceData $trace): string
    {
        if ($trace->outputPayload === null) {
            return $trace->outputText ?: 'Результат отсутствует.';
        }

        $labels = [
            'client_summary' => 'Клиент',
            'main_request' => 'Основной запрос',
            'source_facts' => 'Факты',
            'hypotheses' => 'Гипотезы',
            'critical_limitations_risks' => 'Ограничения и риски',
            'blind_spots_questions' => 'Что уточнить',
            'recommended_first_session_focus' => 'Фокус первой сессии',
            'missing_information' => 'Недостающая информация',
            'exam_type' => 'Тип исследования',
            'anatomical_region' => 'Анатомическая область',
            'key_findings' => 'Ключевые находки',
            'structural_deformations' => 'Структурные изменения',
            'critical_flags' => 'Критические ограничения',
            'plain_summary' => 'Понятное резюме',
            'visual_findings' => 'Визуальные наблюдения',
            'leading_compensatory_patterns' => 'Ведущие компенсаторные паттерны',
            'practitioner_focus' => 'Фокус специалиста',
            'limitations' => 'Ограничения анализа',
        ];
        $sections = [];
        foreach ($trace->outputPayload as $key => $value) {
            $label = $labels[(string) $key] ?? (string) $key;
            $sections[] = '### '.$label."\n".self::humanValue($value);
        }

        return implode("\n\n", $sections);
    }

    private static function humanValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $items[] = '- '.implode(' · ', array_map(
                        static fn (string|int $key, mixed $itemValue): string => (string) $key.': '.self::humanValue($itemValue),
                        array_keys($item),
                        array_values($item),
                    ));
                } else {
                    $items[] = '- '.self::humanValue($item);
                }
            }

            return implode("\n", $items) ?: 'Нет данных.';
        }

        if ($value === null || $value === '') {
            return 'Нет данных.';
        }

        return (string) $value;
    }

    private static function pretty(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Нет данных.';
    }
}
