<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use App\Filament\Support\ClinicalAiPresentation;
use App\Filament\Support\CrmLabel;
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
                Section::make(__('Сводка запуска'))
                    ->schema([
                        TextEntry::make('capability')
                            ->label(__('Возможность'))
                            ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? CrmLabel::enum($state) : (string) $state),
                        TextEntry::make('origin')
                            ->label(__('Источник'))
                            ->formatStateUsing(fn ($state) => CrmLabel::enum($state) ?? (string) $state),
                        TextEntry::make('status')
                            ->label(__('Статус'))
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
                            ->formatStateUsing(fn ($state) => $state instanceof AiRunStatus ? CrmLabel::enum($state) : (string) $state),
                        TextEntry::make('actual_provider')->label(__('Провайдер'))->placeholder('—'),
                        TextEntry::make('actual_model')->label(__('Модель'))->placeholder('—'),
                        TextEntry::make('latency_ms')
                            ->label(__('Время выполнения'))
                            ->formatStateUsing(fn ($state) => $state ? ($state > 1000 ? round($state / 1000, 2).' '.__('с') : $state.' '.__('мс')) : '—'),
                        TextEntry::make('settled_estimated_cost_minor_units')
                            ->label(__('Оценочная стоимость'))
                            ->formatStateUsing(fn ($state) => $state !== null ? '$'.number_format($state / 10000, 4) : '—'),
                        TextEntry::make('human_review_status')
                            ->label(__('Статус проверки'))
                            ->badge()
                            ->wrap()
                            ->columnSpanFull()
                            ->formatStateUsing(fn ($state) => $state instanceof HumanReviewStatus ? CrmLabel::enum($state) : (string) $state),
                    ])
                    ->columns(2),

                Section::make(__('Технические данные'))
                    ->collapsed()
                    ->schema([
                        TextEntry::make('id')->label(__('ID запуска'))->fontFamily('mono'),
                        TextEntry::make('prompt_version_id')->label(__('ID версии промпта'))->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('model_release_id')->label(__('ID релиза модели'))->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('rendered_prompt_digest')->label(__('Хеш промпта'))->fontFamily('mono')->placeholder('—')->wrap(),
                        TextEntry::make('context_hash')
                            ->label(__('Хеш контекста'))
                            ->state(fn (AiRun $record): string => self::contextHash($record))
                            ->fontFamily('mono'),
                        TextEntry::make('technical_input_references')
                            ->label(__('Ссылки на источники'))
                            ->state(fn (AiRun $record): string => self::pretty($record->input_references))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make(__('Ошибки и попытки выполнения'))
                    ->schema([
                        TextEntry::make('error_message_sanitized')
                            ->label(__('Сообщение об ошибке'))
                            ->placeholder(__('Ошибок нет'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('attempts_summary')
                            ->label(__('История попыток'))
                            ->state(function (AiRun $record): string {
                                $attempts = $record->attempts()->orderBy('attempt_number')->get();
                                if ($attempts->isEmpty()) {
                                    return __('Нет записей о попытках.');
                                }
                                $lines = [];
                                foreach ($attempts as $att) {
                                    $rev = $att->credential_revision ? substr($att->credential_revision, 0, 8).'…' : '—';
                                    $cost = '$'.number_format($att->settled_estimated_cost_minor_units / 10000, 4);
                                    $lines[] = __('#:attempt · :provider/:model · Статус: :status · :latencyмс · Стоимость: :cost · Ревизия ключа: :revision', [
                                        'attempt' => $att->attempt_number,
                                        'provider' => $att->provider,
                                        'model' => $att->model,
                                        'status' => $att->status,
                                        'latency' => $att->latency_ms,
                                        'cost' => $cost,
                                        'revision' => $rev,
                                    ]);
                                }

                                return implode("\n", $lines);
                            })
                            ->columnSpanFull()
                            ->wrap(),
                    ]),

                Section::make(__('База знаний (RAG)'))
                    ->schema([
                        TextEntry::make('rag_summary')
                            ->label(__('Использованные фрагменты'))
                            ->state(function (AiRun $record): string {
                                $refs = $record->ragReferences()->orderBy('reference_index')->get();
                                if ($refs->isEmpty()) {
                                    return __('База знаний не использовалась.');
                                }
                                $lines = [];
                                foreach ($refs as $ref) {
                                    $lines[] = __('#:reference · Источник #:source (Фрагмент #:chunk) · Сходство: :similarity%', [
                                        'reference' => $ref->reference_index,
                                        'source' => $ref->knowledge_source_id,
                                        'chunk' => $ref->knowledge_chunk_id,
                                        'similarity' => round($ref->similarity_score * 100, 1),
                                    ]);
                                }

                                return implode("\n", $lines);
                            })
                            ->columnSpanFull()
                            ->wrap(),
                    ]),

                Section::make(__('Защищённый след (Protected Trace)'))
                    ->schema([
                        TextEntry::make('protected_trace_request')
                            ->label(__('Запрос / ввод'))
                            ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->userPrompt ?: __('Ввод отсутствует.')))
                            ->columnSpanFull()
                            ->wrap()
                            ->markdown(),
                        TextEntry::make('protected_trace_sources')
                            ->label(__('Источники ввода'))
                            ->state(fn (AiRun $record): string => self::traceText($record, [self::class, 'sourceText']))
                            ->columnSpanFull()
                            ->wrap()
                            ->markdown(),
                        Section::make(__('Промпт'))
                            ->schema([
                                TextEntry::make('protected_trace_prompt_name')
                                    ->label(__('Промпт и версия'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => __(':name · версия :version', ['name' => $trace->promptName ?? '—', 'version' => $trace->promptVersion ?? '—']))),
                                TextEntry::make('protected_trace_source_prompt')
                                    ->label(__('Исходный текст промпта'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->sourcePrompt ?: __('Исходный текст недоступен.')))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                                TextEntry::make('protected_trace_guardrails')
                                    ->label(__('Платформенные защитные правила'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->platformSafetyGuardrails ?: __('Отдельные правила не записаны.')))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                            ])
                            ->columns(2),
                        Section::make(__('Модель и контекст'))
                            ->schema([
                                TextEntry::make('protected_trace_model')
                                    ->label(__('Модель'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => self::pretty($trace->model)))
                                    ->wrap(),
                                TextEntry::make('protected_trace_context')
                                    ->label(__('Снимок контекста'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => self::pretty($trace->contextProvenance)))
                                    ->wrap(),
                                TextEntry::make('protected_trace_rag')
                                    ->label(__('Источники базы знаний'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => $trace->ragReferences === [] ? __('База знаний не использовалась.') : self::pretty($trace->ragReferences)))
                                    ->columnSpanFull()
                                    ->wrap(),
                            ])
                            ->columns(2),
                        Section::make(__('Результат'))
                            ->schema([
                                TextEntry::make('protected_trace_output')
                                    ->label(__('Результат для специалиста'))
                                    ->state(fn (AiRun $record): string => self::traceText(
                                        $record,
                                        fn (AiRunProtectedTraceData $trace): string => ClinicalAiPresentation::result(
                                            $record->capability,
                                            $trace->outputPayload,
                                            $trace->outputText,
                                        ),
                                    ))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                                TextEntry::make('protected_trace_review')
                                    ->label(__('Проверка специалиста'))
                                    ->state(fn (AiRun $record): string => self::traceText($record, static fn (AiRunProtectedTraceData $trace): string => implode("\n\n", array_filter([
                                        $trace->humanReviewNotes !== null ? __('Заметки: :notes', ['notes' => $trace->humanReviewNotes]) : null,
                                        $trace->humanEditedOutput !== null ? __('Исправленный результат: :result', ['result' => $trace->humanEditedOutput]) : null,
                                    ])) ?: __('Дополнительных заметок нет.')))
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->markdown(),
                            ]),
                        Section::make(__('Показать исходный JSON'))
                            ->collapsed()
                            ->schema([
                                TextEntry::make('protected_trace_raw_json')
                                    ->label(__('Исходный JSON'))
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
            return __('Доступ к защищённому следу ограничен политикой безопасности.');
        }

        return $resolver($trace);
    }

    private static function sourceText(AiRunProtectedTraceData $trace): string
    {
        $labels = [
            'client' => __('Профиль клиента'),
            'medical_attachment' => __('Медицинское вложение'),
            'companion_attachment' => __('Вложение диалога'),
            'medical_session' => __('Медицинский сеанс'),
            'survey_attempt' => __('Результат опроса'),
            'booking' => __('Запись на приём'),
            'ai_run' => __('Предыдущий AI-анализ'),
            'knowledge_source' => __('Источник базы знаний'),
        ];
        $references = collect($trace->inputReferences)
            ->map(static function (array $reference) use ($labels): string {
                $type = (string) ($reference['type'] ?? __('Источник'));
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
            $references !== '' ? $references : __('Явные ссылки на источники не записаны.'),
            __('Извлечённый контекст: :context', ['context' => self::pretty($trace->contextProvenance)]),
        ]));
    }

    private static function pretty(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: __('Нет данных.');
    }
}
