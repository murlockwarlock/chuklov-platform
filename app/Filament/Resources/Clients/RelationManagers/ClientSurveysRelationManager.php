<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\SurveyAttempts\SurveyAttemptResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Application\ListClientSurveyAttemptsForCrm;
use App\Modules\Surveys\Application\SurveyAuthorization;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ClientSurveysRelationManager extends RelationManager
{
    protected static string $relationship = 'surveyAttempts';

    protected static ?string $title = 'Опросы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $ownerRecord instanceof Client
            && app(SurveyAuthorization::class)->allowsView($actor, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);

        return $table
            ->heading('Опросы и отчёты')
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientSurveyAttemptsForCrm::class)->apply($actor, $client, $query),
            )
            ->columns([
                TextColumn::make('surveyDefinition.title')
                    ->label('Опрос')
                    ->placeholder('Без названия')
                    ->wrap(),
                TextColumn::make('surveyVersion.version')->label('Версия')->visibleFrom('sm'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ($state instanceof SurveyAttemptStatus ? $state->value : (string) $state) === 'completed'
                        ? 'Завершён'
                        : 'Не завершён'),
                TextColumn::make('started_at')->label('Начат')->dateTime('d.m.Y H:i'),
                TextColumn::make('completed_at')
                    ->label('Завершён')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->visibleFrom('sm'),
                TextColumn::make('report.title')
                    ->label('Отчёт')
                    ->placeholder('Не сформирован')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (SurveyAttempt $record): string => SurveyAttemptResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([10, 25])
            ->defaultSort('started_at', 'desc')
            ->emptyStateHeading('Опросов пока нет')
            ->emptyStateDescription('Результаты и отчёты этого клиента появятся здесь.');
    }
}
