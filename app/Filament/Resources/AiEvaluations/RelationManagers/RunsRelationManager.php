<?php

namespace App\Filament\Resources\AiEvaluations\RelationManagers;

use App\Filament\Support\AiEvaluationComparisonPresentation;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\AI\Application\Actions\CompareAiEvaluationRuns;
use App\Modules\AI\Application\Services\AiEvaluationRunMetricsReader;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RunsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'История запусков';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    public function table(Table $table): Table
    {
        /** @var AiEvalSuite $suite */
        $suite = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Когда'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('prompt')
                    ->label(__('Промпт'))
                    ->state(fn (AiEvalRun $record): string => self::promptLabel($record)),
                TextColumn::make('model')
                    ->label(__('Модель'))
                    ->state(fn (AiEvalRun $record): string => self::modelLabel($record)),
                TextColumn::make('pass_percentage')
                    ->label(__('Результат'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '').'%'),
                TextColumn::make('failed_cases')->label(__('Не прошли'))->formatStateUsing(fn ($state): string => (string) $state),
                TextColumn::make('estimated_cost_minor_units')
                    ->label(__('Расчётная стоимость Chuklov'))
                    ->formatStateUsing(fn ($state, AiEvalRun $record): string => self::money($state, $record, 'estimated_by_currency')),
                TextColumn::make('provider_cost_minor_units')
                    ->label(__('Стоимость AI-сервиса'))
                    ->formatStateUsing(fn ($state, AiEvalRun $record): string => self::money($state, $record, 'provider_reported_by_currency')),
                TextColumn::make('average_latency_ms')
                    ->label(__('Среднее время'))
                    ->formatStateUsing(fn ($state, AiEvalRun $record): string => is_array($record->metrics_payload)
                        ? self::duration((int) $state)
                        : __('нет данных')),
                TextColumn::make('reliability')
                    ->label(__('Ошибки и повторы'))
                    ->state(fn (AiEvalRun $record): string => is_array($record->metrics_payload)
                        ? __('Ошибки :errors · повторы :retries · резерв :failovers', [
                            'errors' => $record->execution_error_count,
                            'retries' => $record->retry_count,
                            'failovers' => $record->failover_count,
                        ])
                        : __('нет данных')),
            ])
            ->headerActions([
                Action::make('compare_runs')
                    ->label(__('Сравнить запуски'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('info')
                    ->form([
                        Select::make('run_ids')
                            ->label(__('Запуски одной проверки'))
                            ->helperText(__('Выберите от двух до четырёх завершённых запусков. Набор примеров должен совпадать.'))
                            ->multiple()
                            ->options(fn (): array => self::comparisonOptions($suite))
                            ->searchable()
                            ->native(false)
                            ->minItems(2)
                            ->maxItems(4)
                            ->required(),
                    ])
                    ->action(function (array $data, CompareAiEvaluationRuns $compareAction): void {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            return;
                        }

                        $comparison = $compareAction->handle($actor, (array) ($data['run_ids'] ?? []));
                        Notification::make()
                            ->title($comparison->compatible ? __('Сравнение готово') : __('Сравнение недоступно'))
                            ->body(AiEvaluationComparisonPresentation::summary($comparison))
                            ->color($comparison->compatible ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(__('Открыть результат'))
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (AiEvalRun $record): string => __('Результат проверки от ').$record->created_at?->format('d.m.Y H:i'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Закрыть'))
                    ->modalContent(fn (AiEvalRun $record): View => view('filament.resources.ai-evaluations.run-details', [
                        'run' => $record,
                        'metrics' => app(AiEvaluationRunMetricsReader::class)->forRun($record),
                    ])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25]);
    }

    /** @return array<int|string, string> */
    private static function comparisonOptions(AiEvalSuite $suite): array
    {
        return AiEvalRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('eval_suite_id', $suite->getKey())
            ->whereNotNull('provenance_snapshot')
            ->latest('created_at')
            ->limit(40)
            ->get()
            ->mapWithKeys(static function (AiEvalRun $run): array {
                $prompt = self::promptLabel($run);
                $model = self::modelLabel($run);
                $date = $run->created_at instanceof Carbon ? $run->created_at->format('d.m.Y H:i') : __('дата недоступна');

                return [$run->getKey() => $date.' · '.$prompt.' · '.$model.' · '.number_format((float) $run->pass_percentage, 2, ',', '').'%'];
            })
            ->all();
    }

    private static function promptLabel(AiEvalRun $run): string
    {
        $snapshot = is_array($run->provenance_snapshot) ? $run->provenance_snapshot : [];
        $promptVersion = is_array($snapshot['prompt_version'] ?? null) ? $snapshot['prompt_version'] : [];

        return is_scalar($promptVersion['version'] ?? null)
            ? 'v'.(int) $promptVersion['version']
            : __('промпт недоступен');
    }

    private static function modelLabel(AiEvalRun $run): string
    {
        $snapshot = is_array($run->provenance_snapshot) ? $run->provenance_snapshot : [];
        $modelRelease = is_array($snapshot['model_release'] ?? null) ? $snapshot['model_release'] : [];
        if (! is_scalar($modelRelease['provider'] ?? null)
            || ! is_scalar($modelRelease['model'] ?? null)
            || ! is_scalar($modelRelease['release_number'] ?? null)) {
            return __('модель недоступна');
        }

        return $modelRelease['provider'].' · '.$modelRelease['model'].' · '.__('Выпуск :version', ['version' => $modelRelease['release_number']]);
    }

    private static function duration(int $milliseconds): string
    {
        $decimalSeparator = app()->getLocale() === 'en' ? '.' : ',';

        return $milliseconds > 1000
            ? __(':value с', ['value' => number_format($milliseconds / 1000, 2, $decimalSeparator, '')])
            : __(':value мс', ['value' => $milliseconds]);
    }

    private static function money(mixed $state, AiEvalRun $run, string $metricKey): string
    {
        $metrics = is_array($run->metrics_payload) ? $run->metrics_payload : [];
        $unknownCount = (int) ($metrics['cost'][$metricKey === 'estimated_by_currency'
            ? 'estimated_currency_unknown_count'
            : 'provider_reported_unknown_count']
            ?? $metrics['cost']['provider_reported_currency_unknown_count']
            ?? 0);
        if ($unknownCount > 0) {
            return __('нет данных: стоимость не сообщена или валюта неизвестна');
        }

        if ($state === null) {
            return __('нет данных');
        }

        $costs = $metrics['cost'][$metricKey] ?? [];
        if (! is_array($costs) || $costs === []) {
            return __('нет данных');
        }

        $currency = (string) array_key_first($costs);

        return $currency.' '.number_format(((int) $state) / 100, 2, ',', ' ');
    }
}
