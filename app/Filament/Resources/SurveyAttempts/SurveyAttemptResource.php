<?php

namespace App\Filament\Resources\SurveyAttempts;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\SurveyAttempts\Pages\ListSurveyAttempts;
use App\Filament\Resources\SurveyAttempts\Pages\ViewSurveyAttempt;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedResource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SurveyAttemptResource extends LocalizedResource
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
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')
                    ->label(__('Клиент'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (SurveyAttempt $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients))
                    ->color(fn (SurveyAttempt $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary')
                    ->disabledClick(fn (SurveyAttempt $record): bool => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null),
                TextColumn::make('surveyDefinition.title')->label(__('Тест'))->sortable()->wrap(),
                TextColumn::make('surveyVersion.version')->label(__('Версия'))->visibleFrom('sm'),
                TextColumn::make('status')
                    ->label(__('Статус'))
                    ->badge()
                    ->formatStateUsing(fn (SurveyAttemptStatus $state): string => CrmLabel::enum($state) ?? __('Неизвестный статус')),
                TextColumn::make('completed_at')->label(__('Завершён'))->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
            ])
            ->recordActions([ViewAction::make()->label(__('Открыть'))])
            ->defaultSort('started_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Параметры теста'))
                ->schema([
                    TextEntry::make('client.full_name')
                        ->label(__('Клиент'))
                        ->wrap()
                        ->url(fn (SurveyAttempt $record): ?string => CrmEntityLinks::clientUrl($record->client))
                        ->color(fn (SurveyAttempt $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary'),
                    TextEntry::make('surveyDefinition.title')->label(__('Тест'))->wrap(),
                    TextEntry::make('surveyVersion.version')->label(__('Версия')),
                    TextEntry::make('started_at')->label(__('Начат'))->dateTime('d.m.Y H:i'),
                    TextEntry::make('completed_at')->label(__('Завершён'))->dateTime('d.m.Y H:i')->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('Результаты и метрики'))
                ->schema([
                    KeyValueEntry::make('result_metrics')
                        ->label(__('Показатели'))
                        ->state(fn (SurveyAttempt $record): array => self::metricDisplay($record))
                        ->columnSpanFull(),
                    TextEntry::make('result_thresholds')
                        ->label(__('Пороговые результаты'))
                        ->state(fn (SurveyAttempt $record): string => self::thresholdDisplay($record))
                        ->placeholder(__('Нет'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['client:id,organization_id,full_name', 'surveyDefinition:id,title', 'surveyVersion:id,version']);
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
        $metricNumber = 0;
        foreach ($metrics as $metric) {
            if (is_array($metric)) {
                $metricNumber++;
                $label = self::humanLabel($metric['label'] ?? null, __('Показатель :number', ['number' => $metricNumber]));
                $display[$label] = (string) ($metric['value'] ?? '');
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
