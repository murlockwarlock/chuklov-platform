<?php

namespace App\Filament\Resources\AiRuns;

use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedResource;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AiRunResource extends LocalizedResource
{
    protected static ?string $model = AiRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'История запусков';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'запуск AI';

    protected static ?string $pluralModelLabel = 'запуски AI';

    protected static ?string $breadcrumb = 'История запусков';

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('capability')
                    ->label(__('Возможность'))
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? CrmLabel::enum($state) : (string) $state)
                    ->searchable(),
                TextColumn::make('origin')
                    ->label(__('Источник'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => CrmLabel::enum($state) ?? (string) $state),
                TextColumn::make('status')
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
                TextColumn::make('human_review_status')
                    ->label(__('Проверка'))
                    ->badge()
                    ->wrap()
                    ->color(fn ($state): string => match ($state instanceof HumanReviewStatus ? $state->value : (string) $state) {
                        'accepted' => 'success',
                        'pending_review' => 'warning',
                        'rejected' => 'danger',
                        'edited_and_accepted' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof HumanReviewStatus ? CrmLabel::enum($state) : (string) $state),
                TextColumn::make('actual_model')
                    ->label(__('Модель'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('settled_estimated_cost_minor_units')
                    ->label(__('Стоимость'))
                    ->formatStateUsing(fn ($state) => $state !== null ? '$'.number_format($state / 10000, 4) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latency_ms')
                    ->label(__('Время'))
                    ->formatStateUsing(fn ($state) => $state ? ($state > 1000 ? round($state / 1000, 2).' '.__('с') : $state.' '.__('мс')) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('Создан'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('capability')
                    ->label(__('Возможность'))
                    ->options(collect(AiCapability::cases())->mapWithKeys(fn ($c) => [$c->value => CrmLabel::enum($c)])),
                SelectFilter::make('status')
                    ->label(__('Статус'))
                    ->options(collect(AiRunStatus::cases())->mapWithKeys(fn ($s) => [$s->value => CrmLabel::enum($s)])),
                SelectFilter::make('human_review_status')
                    ->label(__('Проверка'))
                    ->options(collect(HumanReviewStatus::cases())->mapWithKeys(fn ($h) => [$h->value => CrmLabel::enum($h)])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('Открыть'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip(__('Открыть запуск')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRuns::route('/'),
            'view' => ViewAiRun::route('/{record}'),
        ];
    }
}
