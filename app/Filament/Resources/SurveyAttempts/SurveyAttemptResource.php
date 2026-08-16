<?php

namespace App\Filament\Resources\SurveyAttempts;

use App\Filament\Resources\SurveyAttempts\Pages\ListSurveyAttempts;
use App\Filament\Resources\SurveyAttempts\Pages\ViewSurveyAttempt;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SurveyAttemptResource extends Resource
{
    protected static ?string $model = SurveyAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Результаты тестов';

    protected static string|\UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'результат теста';

    protected static ?string $pluralModelLabel = 'результаты тестов';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('client.full_name')->label('Клиент')->searchable()->sortable(),
            TextColumn::make('surveyDefinition.title')->label('Тест')->sortable(),
            TextColumn::make('surveyVersion.version')->label('Версия'),
            TextColumn::make('status')->label('Статус')->formatStateUsing(fn ($state): string => $state->value === 'completed' ? 'Завершён' : 'Не завершён'),
            TextColumn::make('completed_at')->label('Завершён')->dateTime('d-m-Y H:i')->placeholder('—')->sortable(),
        ])->recordActions([ViewAction::make()])->defaultSort('started_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.full_name')->label('Клиент'),
            TextEntry::make('surveyDefinition.title')->label('Тест'),
            TextEntry::make('surveyVersion.version')->label('Версия'),
            TextEntry::make('started_at')->label('Начат')->dateTime('d-m-Y H:i'),
            TextEntry::make('completed_at')->label('Завершён')->dateTime('d-m-Y H:i')->placeholder('—'),
            KeyValueEntry::make('result_metrics')->label('Показатели')->state(fn (SurveyAttempt $record): array => self::metricDisplay($record))->columnSpanFull(),
            TextEntry::make('result_tags')->label('Отметки результата')->state(fn (SurveyAttempt $record): string => implode(', ', $record->result_snapshot['tags'] ?? []))->placeholder('Нет'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id())->with(['client:id,full_name', 'surveyDefinition:id,title', 'surveyVersion:id,version']);
    }

    public static function getPages(): array
    {
        return ['index' => ListSurveyAttempts::route('/'), 'view' => ViewSurveyAttempt::route('/{record}')];
    }

    /** @return array<string, string> */
    private static function metricDisplay(SurveyAttempt $attempt): array
    {
        $display = [];
        $metrics = $attempt->result_snapshot['metrics'] ?? [];
        if (! is_array($metrics)) {
            return [];
        }
        foreach ($metrics as $key => $metric) {
            if (is_array($metric)) {
                $display[(string) ($metric['label'] ?? $key)] = (string) ($metric['value'] ?? '');
            }
        }

        return $display;
    }
}
