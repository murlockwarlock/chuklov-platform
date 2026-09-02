<?php

namespace App\Filament\Resources\ScenarioRules\Tables;

use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

final class ScenarioRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label('Авто-сообщение')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('trigger_event')
                    ->label('Когда')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextColumn::make('recipient_summary')
                    ->label('Кому')
                    ->state(fn (ScenarioRule $record): string => self::recipientLabel($record)),
                TextColumn::make('message_summary')
                    ->label('Что отправить')
                    ->state(fn (ScenarioRule $record): string => self::messageLabel($record))
                    ->wrap(),
                IconColumn::make('is_enabled')
                    ->label('Включено')
                    ->boolean()
                    ->sortable(),
            ])
            ->emptyStateHeading('Авто-сообщений пока нет')
            ->emptyStateDescription('Создайте авто-сообщение, чтобы отправлять клиенту нужный текст после события.')
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->tooltip('Открыть авто-сообщение'),
                EditAction::make()
                    ->label('Редактировать')
                    ->icon('heroicon-o-pencil')
                    ->iconButton()
                    ->tooltip('Редактировать авто-сообщение'),
            ]);
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            ScenarioEventType::BookingCreated->value => 'После новой записи',
            ScenarioEventType::BookingConfirmed->value => 'После подтверждения',
            ScenarioEventType::BookingRescheduled->value => 'После переноса',
            ScenarioEventType::BookingCancelled->value => 'После отмены',
            ScenarioEventType::BookingCompleted->value => 'После визита',
            ScenarioEventType::OnboardingStarted->value => 'После начала оформления',
            ScenarioEventType::FinancialObligationCreated->value => 'После появления задолженности',
            ScenarioEventType::SurveyCompleted->value => 'После теста',
            ScenarioEventType::TestStagnationDetected->value => 'Если показатели не снижаются',
            ScenarioEventType::B2bLeadSubmitted->value => 'После B2B-запроса',
            ScenarioEventType::B2bSalesCallReady->value => 'Когда B2B-разговор готов',
            default => 'Событие',
        };
    }

    private static function recipientLabel(ScenarioRule $record): string
    {
        return match ($record->recipient_strategy['type'] ?? null) {
            'client' => 'Клиент',
            'assigned_specialist' => 'Специалист',
            'members' => 'Выбранные сотрудники',
            'roles' => 'Сотрудники по роли',
            default => 'Не указано',
        };
    }

    private static function messageLabel(ScenarioRule $record): string
    {
        $template = $record->templateVersion?->template;
        $body = trim((string) $record->templateVersion?->body);

        return ($template?->name ?: 'Сообщение')
            .($body === '' ? '' : ' · '.Str::limit($body, 70));
    }
}
