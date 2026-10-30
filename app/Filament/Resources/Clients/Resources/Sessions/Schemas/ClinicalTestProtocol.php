<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

final class ClinicalTestProtocol
{
    /** @return list<array<string, mixed>> */
    public static function defaultState(): array
    {
        return self::stateFor([]);
    }

    /** @param array<int, mixed> $results
     * @return list<array{test_id: int, test_label: string, before_value_id: mixed, after_value_id: mixed, cause_ids: list<mixed>}>
     */
    public static function stateFor(array $results): array
    {
        $selected = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->map(static fn (array $result): int => (int) ($result['test_id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $existing = collect($results)->filter(static fn (mixed $result): bool => is_array($result))
            ->keyBy(static fn (array $result): int => (int) ($result['test_id'] ?? 0));

        return array_values(app(MedicalClinicalCatalog::class)
            ->testProtocolRows(app(OrganizationContext::class)->id(), $selected)
            ->map(static function (MedicalTestDefinition $test) use ($existing): array {
                $result = $existing->get((int) $test->getKey(), []);

                return [
                    'test_id' => (int) $test->getKey(),
                    'test_label' => filled($test->section) ? $test->section.' · '.$test->name : (string) $test->name,
                    'before_value_id' => $result['before_value_id'] ?? null,
                    'after_value_id' => $result['after_value_id'] ?? null,
                    'cause_ids' => is_array($result['cause_ids'] ?? null) ? array_values($result['cause_ids']) : [],
                ];
            })
            ->values()
            ->all());
    }

    public static function field(): Repeater
    {
        return Repeater::make('test_results')
            ->label(__('Клинические тесты'))
            ->schema([
                Hidden::make('test_id')->dehydrated(),
                Hidden::make('test_label')->dehydrated(false),
                Select::make('before_value_id')
                    ->label(__('До сеанса'))
                    ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id'), [$get('before_value_id')]))
                    ->placeholder(__('Не заполнено'))
                    ->nullable()
                    ->searchable()
                    ->live(),
                Select::make('after_value_id')
                    ->label(__('После сеанса'))
                    ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id'), [$get('after_value_id')]))
                    ->placeholder(__('Не заполнено'))
                    ->nullable()
                    ->searchable()
                    ->live(),
                Select::make('cause_ids')
                    ->label(__('Причины нарушений'))
                    ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->causeOptionsForSelection(app(OrganizationContext::class)->id(), (array) $get('cause_ids')))
                    ->multiple()
                    ->searchable(),
            ])
            ->default(self::defaultState())
            ->itemLabel(fn (array $state): string => (string) ($state['test_label'] ?? __('Тест')))
            ->defaultItems(0)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->collapsible(false)
            ->columnSpanFull();
    }

    public static function counters(): Placeholder
    {
        return Placeholder::make('test_counters')
            ->label(__('Нарушения'))
            ->content(function (Get $get): string {
                $counts = app(MedicalClinicalCatalog::class)->abnormalCounts(
                    app(OrganizationContext::class)->id(),
                    is_array($get('test_results')) ? $get('test_results') : [],
                );

                return __('До сеанса: :before · После сеанса: :after', $counts);
            })
            ->live()
            ->columnSpanFull();
    }
}
