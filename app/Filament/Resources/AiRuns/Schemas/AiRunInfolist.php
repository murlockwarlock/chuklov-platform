<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetAiRunProtectedTrace;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class AiRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')->label('ID запуска'),
                TextEntry::make('capability')
                    ->label('Возможность')
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? $state->label() : (string) $state),
                TextEntry::make('origin')
                    ->label('Источник')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                TextEntry::make('status')
                    ->label('Статус')
                    ->badge()
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
                    ->formatStateUsing(fn ($state) => $state instanceof HumanReviewStatus ? $state->label() : (string) $state),
                TextEntry::make('error_message_sanitized')
                    ->label('Сообщение об ошибке')
                    ->placeholder('Ошибок нет')
                    ->columnSpanFull(),
                TextEntry::make('attempts_summary')
                    ->label('Попытки выполнения')
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
                    ->columnSpanFull(),
                TextEntry::make('rag_summary')
                    ->label('Использованные фрагменты базы знаний (RAG)')
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
                    ->columnSpanFull(),
                TextEntry::make('protected_trace')
                    ->label('Защищенный след (Protected Trace)')
                    ->state(function (AiRun $record): string {
                        $user = Auth::user();
                        if (! $user instanceof User) {
                            return 'Требуется аутентификация.';
                        }

                        $traceAction = app(GetAiRunProtectedTrace::class);

                        try {
                            $trace = $traceAction->handle($user, $record->id);

                            return $trace->outputText ?: 'Текст вывода отсутствует.';
                        } catch (AuthorizationException) {
                            return 'Доступ к защищенному следу ограничен политикой безопасности (требуется разрешение ViewAiTrace).';
                        } catch (\Throwable) {
                            return 'След не найден.';
                        }
                    })
                    ->columnSpanFull(),
            ]);
    }
}
