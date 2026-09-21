<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class SessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Сеанс'))
                    ->schema([
                        DateTimePicker::make('occurred_at')
                            ->label(__('Дата и время сеанса'))
                            ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                            ->required()
                            ->seconds(false)
                            ->placeholder(__('Выберите дату и время сеанса')),
                        Select::make('specialist_id')
                            ->label(__('Специалист'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->preload()
                            ->optionsLimit(50)
                            ->options(static fn (): array => self::specialistResults(''))
                            ->getSearchResultsUsing(static fn (string $search): array => self::specialistResults($search))
                            ->getOptionLabelUsing(static fn (mixed $value): ?string => self::specialistLabel($value)),
                        Select::make('booking_id')
                            ->label(__('Запись на приём (необязательно)'))
                            ->helperText(__('Связан только с записями этого клиента и выбранного специалиста.'))
                            ->placeholder(__('Без записи на приём'))
                            ->searchable()
                            ->native(false)
                            ->getSearchResultsUsing(static fn (string $search, Select $component): array => self::bookingResults($search, $component))
                            ->getOptionLabelUsing(static fn (mixed $value, Select $component): ?string => self::bookingLabel($value, $component)),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('Клинические заметки (опционально)'))
                    ->schema([
                        Textarea::make('pain')->label(__('Боль'))->rows(3)->placeholder(__('Что беспокоит клиента и где')),
                        Textarea::make('tests')->label(__('Тесты'))->rows(3)->placeholder(__('Проведённые проверки и их результаты')),
                        Textarea::make('observations')->label(__('Наблюдения'))->rows(3)->placeholder(__('Субъективные и объективные наблюдения')),
                        Textarea::make('root_cause_hypothesis')->label(__('Гипотеза первопричины'))->rows(3)->placeholder(__('Предполагаемая причина состояния')),
                        Textarea::make('protocol')->label(__('Протокол'))->rows(3)->placeholder(__('Назначенные процедуры и план')),
                        Textarea::make('result')->label(__('Результат'))->rows(3)->placeholder(__('Эффект после процедур/до следующего сеанса')),
                    ])->columnSpanFull(),
            ]);
    }

    private static function parentClient(Select $component): Client
    {
        $livewire = $component->getLivewire();
        abort_unless(method_exists($livewire, 'getParentRecord'), 404);
        $parent = $livewire->getParentRecord();

        abort_unless($parent instanceof Client, 404);

        return $parent;
    }

    /** @return array<int|string, string> */
    private static function specialistResults(string $search): array
    {
        $query = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->when(trim($search) !== '', fn (Builder $query) => $query->where('display_name', 'like', '%'.trim($search).'%'))
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->limit(50);

        return $query->get(['id', 'display_name', 'is_active'])
            ->mapWithKeys(static fn (Specialist $specialist): array => [
                $specialist->getKey() => self::specialistPresentation($specialist),
            ])
            ->all();
    }

    private static function specialistLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->first();

        return $specialist instanceof Specialist ? self::specialistPresentation($specialist) : null;
    }

    private static function specialistPresentation(Specialist $specialist): string
    {
        return __(':name (:status)', [
            'name' => $specialist->display_name,
            'status' => $specialist->is_active ? __('активен') : __('неактивен'),
        ]);
    }

    /** @return array<int|string, string> */
    private static function bookingResults(string $search, Select $component): array
    {
        $query = self::bookingQuery($component);
        $normalized = trim($search);

        if (ctype_digit($normalized)) {
            $query->whereKey((int) $normalized);
        } elseif ($normalized !== '') {
            try {
                $query->whereDate('starts_at', Carbon::parse($normalized));
            } catch (\Throwable) {
                $query->whereKey(0);
            }
        }

        return $query
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get(['id', 'starts_at'])
            ->mapWithKeys(static fn (Booking $booking): array => [
                $booking->getKey() => Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                    ->format('d.m.Y H:i').' (#'.$booking->getKey().')',
            ])
            ->all();
    }

    private static function bookingLabel(mixed $value, Select $component): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $booking = self::bookingQuery($component)
            ->whereKey((int) $value)
            ->first(['id', 'starts_at']);

        return $booking instanceof Booking
            ? Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                ->format('d.m.Y H:i').' (#'.$booking->getKey().')'
            : null;
    }

    /** @return Builder<Booking> */
    private static function bookingQuery(Select $component): Builder
    {
        $parent = self::parentClient($component);
        $specialistId = (int) ($component->getLivewire()->data['specialist_id'] ?? 0);

        return Booking::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $parent->getKey())
            ->when($specialistId > 0, fn (Builder $query) => $query->where('specialist_id', $specialistId));
    }
}
