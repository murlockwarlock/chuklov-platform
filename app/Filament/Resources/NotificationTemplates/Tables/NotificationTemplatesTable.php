<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Сообщение')->searchable()->sortable(),
                TextColumn::make('locale')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский')
                    ->sortable(),
                TextColumn::make('purpose')
                    ->label('Назначение')
                    ->badge()
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                TextColumn::make('latest_version')
                    ->label('Состояние текста')
                    ->state(function (NotificationTemplate $record): string {
                        $latest = $record->versions->sortByDesc('version')->first();

                        return $latest === null ? 'Нет текста' : 'Текст сохранён';
                    }),
                IconColumn::make('is_active')->label('Включён')->boolean()->sortable(),
            ])
            ->emptyStateHeading('Шаблонов сообщений пока нет')
            ->emptyStateDescription('Создайте текст сообщения, а затем настройте автоматическую отправку в «Правилах сообщений».')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return match ($purpose) {
            ScenarioRulePurpose::Service => 'Сервисное',
            ScenarioRulePurpose::Transactional => 'Системное',
            ScenarioRulePurpose::Marketing => 'Маркетинговое',
            default => 'Не указано',
        };
    }
}
