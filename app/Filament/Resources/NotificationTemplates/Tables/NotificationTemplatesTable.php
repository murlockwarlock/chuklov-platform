<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Filament\Support\RichTextPresentation;
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
                    ->label(__('Сообщение'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('latestVersion.body')
                    ->label(__('Предпросмотр'))
                    ->formatStateUsing(fn (?string $state): string => Str::limit(RichTextPresentation::text($state), 90))
                    ->placeholder(__('Текст не добавлен'))
                    ->wrap(),
                TextColumn::make('locale')
                    ->label(__('Язык'))
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? __('Русский') : __('Английский'))
                    ->sortable(),
                TextColumn::make('purpose')
                    ->label(__('Для чего'))
                    ->badge()
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                IconColumn::make('is_active')
                    ->label(__('Включён'))
                    ->boolean()
                    ->sortable(),
            ])
            ->emptyStateHeading(__('Шаблонов сообщений пока нет'))
            ->emptyStateDescription(__('Создайте текст сообщения, а затем настройте автоматическую отправку в «Авто-сообщениях».'))
            ->recordActions([
                ViewAction::make()
                    ->label(__('Открыть'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip(__('Открыть сообщение')),
                EditAction::make()
                    ->label(__('Редактировать'))
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip(__('Редактировать сообщение')),
            ]);
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return match ($purpose) {
            ScenarioRulePurpose::Service => __('Сервисное'),
            ScenarioRulePurpose::Transactional => __('Системное'),
            ScenarioRulePurpose::Marketing => __('Рассылка'),
            default => __('Не указано'),
        };
    }
}
