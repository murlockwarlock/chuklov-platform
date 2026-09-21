<?php

namespace App\Filament\Resources\TrackerPlans\Tables;

use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class TrackerPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('currentVersion.version')->label(__('Версия'))->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state),
                TextColumn::make('currentVersion.price_minor')->label(__('Цена'))->state(function (TrackerPlan $record): string {
                    $version = $record->currentVersion;

                    return $version instanceof TrackerPlanVersion ? Money::ofMinor($version->price_minor, $version->currencyCode())->toDecimalString().' '.$version->currencyCode()->value : '—';
                }),
                TextColumn::make('currentVersion.duration_days')->label(__('Доступ'))->suffix(' дн.'),
                IconColumn::make('is_active')->label(__('Активен'))->boolean(),
                IconColumn::make('is_visible')->label(__('Виден клиентам'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Активен'))])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('Тарифов пока нет'))
            ->emptyStateDescription(__('Добавьте первый тариф, чтобы предложить клиентам доступ к трекеру.'));
    }
}
