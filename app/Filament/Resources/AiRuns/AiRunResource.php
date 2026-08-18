<?php

namespace App\Filament\Resources\AiRuns;

use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AiRunResource extends Resource
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
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('capability')
                    ->label('Возможность')
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? $state->label() : (string) $state)
                    ->searchable(),
                TextColumn::make('origin')
                    ->label('Источник')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                TextColumn::make('status')
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
                TextColumn::make('human_review_status')
                    ->label('Проверка')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof HumanReviewStatus ? $state->value : (string) $state) {
                        'accepted' => 'success',
                        'pending_review' => 'warning',
                        'rejected' => 'danger',
                        'edited_and_accepted' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof HumanReviewStatus ? $state->label() : (string) $state),
                TextColumn::make('actual_model')
                    ->label('Модель')
                    ->placeholder('—'),
                TextColumn::make('settled_estimated_cost_minor_units')
                    ->label('Стоимость')
                    ->formatStateUsing(fn ($state) => $state !== null ? '$'.number_format($state / 10000, 4) : '—'),
                TextColumn::make('latency_ms')
                    ->label('Время')
                    ->formatStateUsing(fn ($state) => $state ? ($state > 1000 ? round($state / 1000, 2).' с' : $state.' мс') : '—'),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('capability')
                    ->label('Возможность')
                    ->options(collect(AiCapability::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(AiRunStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                SelectFilter::make('human_review_status')
                    ->label('Проверка')
                    ->options(collect(HumanReviewStatus::cases())->mapWithKeys(fn ($h) => [$h->value => $h->label()])),
            ])
            ->recordActions([
                ViewAction::make(),
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
