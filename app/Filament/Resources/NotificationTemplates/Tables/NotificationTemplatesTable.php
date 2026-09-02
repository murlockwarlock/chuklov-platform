<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('name')
                    ->label('Сообщение')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('latestVersion.body')
                    ->label('Предпросмотр')
                    ->formatStateUsing(fn (?string $state): string => Str::limit(trim((string) $state), 90))
                    ->placeholder('Текст не добавлен')
                    ->wrap(),
                TextColumn::make('locale')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский')
                    ->sortable(),
                TextColumn::make('purpose')
                    ->label('Для чего')
                    ->badge()
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                IconColumn::make('is_active')
                    ->label('Включён')
                    ->boolean()
                    ->sortable(),
            ])
            ->emptyStateHeading('Шаблонов сообщений пока нет')
            ->emptyStateDescription('Создайте текст сообщения, а затем настройте автоматическую отправку в «Авто-сообщениях».')
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть сообщение'),
                EditAction::make()
                    ->label('Редактировать')
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip('Редактировать сообщение'),
            ]);
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return match ($purpose) {
            ScenarioRulePurpose::Service => 'Сервисное',
            ScenarioRulePurpose::Transactional => 'Системное',
            ScenarioRulePurpose::Marketing => 'Рассылка',
            default => 'Не указано',
        };
    }
}
