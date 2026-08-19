<?php

namespace App\Filament\Resources\SurveyAttempts;

use App\Filament\Resources\SurveyAttempts\Pages\ListSurveyAttempts;
use App\Filament\Resources\SurveyAttempts\Pages\ViewSurveyAttempt;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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

    protected static ?string $breadcrumb = 'Результаты тестов';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')->label('Клиент')->searchable()->sortable()->wrap(),
                TextColumn::make('surveyDefinition.title')->label('Тест')->sortable()->wrap(),
                TextColumn::make('surveyVersion.version')->label('Версия')->visibleFrom('sm'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (SurveyAttemptStatus $state): string => $state === SurveyAttemptStatus::Completed ? 'Завершён' : 'Не завершён'),
                TextColumn::make('completed_at')->label('Завершён')->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
            ])
            ->recordActions([ViewAction::make()->label('Открыть')])
            ->defaultSort('started_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Параметры теста')
                ->schema([
                    TextEntry::make('client.full_name')->label('Клиент')->wrap(),
                    TextEntry::make('surveyDefinition.title')->label('Тест')->wrap(),
                    TextEntry::make('surveyVersion.version')->label('Версия'),
                    TextEntry::make('started_at')->label('Начат')->dateTime('d.m.Y H:i'),
                    TextEntry::make('completed_at')->label('Завершён')->dateTime('d.m.Y H:i')->placeholder('—'),
                ])
                ->columns(2),

            Section::make('Результаты и метрики')
                ->schema([
                    KeyValueEntry::make('result_metrics')
                        ->label('Показатели')
                        ->state(fn (SurveyAttempt $record): array => self::metricDisplay($record))
                        ->columnSpanFull(),
                    TextEntry::make('result_thresholds')
                        ->label('Пороговые результаты')
                        ->state(fn (SurveyAttempt $record): string => self::thresholdDisplay($record))
                        ->placeholder('Нет')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['client:id,full_name', 'surveyDefinition:id,title', 'surveyVersion:id,version']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurveyAttempts::route('/'),
            'view' => ViewSurveyAttempt::route('/{record}'),
        ];
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
                $label = $metric['label'] ?? $key;
                $display[self::humanLabel($label, (string) $key)] = (string) ($metric['value'] ?? '');
            }
        }

        return $display;
    }

    private static function thresholdDisplay(SurveyAttempt $attempt): string
    {
        $thresholds = $attempt->result_snapshot['thresholds'] ?? [];
        if (! is_array($thresholds)) {
            return '';
        }

        $labels = [];
        foreach ($thresholds as $threshold) {
            if (is_array($threshold)) {
                $label = self::humanLabel($threshold['label'] ?? null, '');
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    private static function humanLabel(mixed $value, string $fallback): string
    {
        if (is_array($value)) {
            return (string) ($value['ru'] ?? $value['en'] ?? $fallback);
        }

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
