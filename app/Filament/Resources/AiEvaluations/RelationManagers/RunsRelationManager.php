<?php

namespace App\Filament\Resources\AiEvaluations\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\CompareAiEvaluationRuns;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'История запусков';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    public function table(Table $table): Table
    {
        /** @var AiEvalSuite $suite */
        $suite = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['promptVersion', 'modelRelease']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('promptVersion.version')
                    ->label('Промпт')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : 'v'.$state),
                TextColumn::make('modelRelease')
                    ->label('Модель')
                    ->state(fn (AiEvalRun $record): string => $record->modelRelease === null
                        ? '—'
                        : $record->modelRelease->provider_name.' · '.$record->modelRelease->model_name.' · выпуск '.$record->modelRelease->release_number),
                TextColumn::make('pass_percentage')
                    ->label('Результат')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '').'%'),
                TextColumn::make('failed_cases')->label('Не прошли')->formatStateUsing(fn ($state): string => (string) $state),
                TextColumn::make('estimated_cost_minor_units')
                    ->label('Расчётная стоимость Chuklov')
                    ->formatStateUsing(fn ($state, AiEvalRun $record): string => self::money($state, $record, 'estimated_by_currency')),
                TextColumn::make('provider_cost_minor_units')
                    ->label('Стоимость от провайдера')
                    ->formatStateUsing(fn ($state, AiEvalRun $record): string => self::money($state, $record, 'provider_reported_by_currency')),
                TextColumn::make('average_latency_ms')
                    ->label('Среднее время')
                    ->formatStateUsing(fn ($state): string => self::duration((int) $state)),
                TextColumn::make('reliability')
                    ->label('Ошибки и повторы')
                    ->state(fn (AiEvalRun $record): string => sprintf(
                        'ошибки %d · повторы %d · резерв %d',
                        $record->execution_error_count,
                        $record->retry_count,
                        $record->failover_count,
                    )),
            ])
            ->headerActions([
                Action::make('compare_runs')
                    ->label('Сравнить запуски')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('info')
                    ->form([
                        Select::make('run_ids')
                            ->label('Запуски одной проверки')
                            ->helperText('Выберите от двух до четырёх завершённых запусков. Набор примеров должен совпадать.')
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
                            ->title($comparison->compatible ? 'Сравнение готово' : 'Сравнение недоступно')
                            ->body($comparison->toRussianSummary())
                            ->color($comparison->compatible ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Открыть результат')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (AiEvalRun $record): string => 'Результат проверки от '.$record->created_at?->format('d.m.Y H:i'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalContent(fn (AiEvalRun $record): View => view('filament.resources.ai-evaluations.run-details', [
                        'run' => $record,
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
            ->with(['promptVersion', 'modelRelease'])
            ->latest('created_at')
            ->limit(40)
            ->get()
            ->mapWithKeys(static function (AiEvalRun $run): array {
                $prompt = 'v'.$run->promptVersion->version;
                $model = $run->modelRelease === null ? 'модель недоступна' : $run->modelRelease->model_name.' · выпуск '.$run->modelRelease->release_number;
                $date = $run->created_at instanceof Carbon ? $run->created_at->format('d.m.Y H:i') : 'дата недоступна';

                return [$run->getKey() => $date.' · '.$prompt.' · '.$model.' · '.number_format((float) $run->pass_percentage, 2, ',', '').'%'];
            })
            ->all();
    }

    private static function duration(int $milliseconds): string
    {
        return $milliseconds > 1000
            ? number_format($milliseconds / 1000, 2, ',', '').' с'
            : $milliseconds.' мс';
    }

    private static function money(mixed $state, AiEvalRun $run, string $metricKey): string
    {
        if ($state === null) {
            return '—';
        }

        $metrics = is_array($run->metrics_payload) ? $run->metrics_payload : [];
        $costs = $metrics['cost'][$metricKey] ?? [];
        if (! is_array($costs) || $costs === []) {
            return 'нет данных';
        }

        $currency = (string) array_key_first($costs);

        return $currency.' '.number_format(((int) $state) / 100, 2, ',', ' ');
    }
}
