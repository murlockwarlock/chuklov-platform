<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\OrganizationExchangeRate;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;
use UnitEnum;

/** @property-read Schema $form */
final class FinanceConfiguration extends Page
{
    protected static ?string $title = 'Настройки валют';

    protected static ?string $navigationLabel = 'Настройки валют';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public bool $configurationUnavailable = false;

    protected string $view = 'filament.pages.finance-configuration';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(FinanceAuthorization::class)->allowsView($actor);
        } catch (LogicException|AuthorizationException) {
            return false;
        }
    }

    public static function canManage(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(FinanceAuthorization::class)->allowsManage($actor);
        } catch (LogicException|AuthorizationException) {
            return false;
        }
    }

    public function mount(): void
    {
        $organizationId = app(OrganizationContext::class)->id();
        $catalog = app(CurrencyCatalog::class);
        $model = OrganizationCurrencyConfiguration::query()
            ->where('organization_id', $organizationId)
            ->first();
        $defaultAllowed = [];
        $defaultBase = null;
        $defaultDisplay = null;
        $defaultForceSingle = false;

        if ($model === null) {
            foreach (Service::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('price_currency')
                ->distinct()
                ->pluck('price_currency') as $currency) {
                try {
                    $code = $catalog->code($currency)->value;
                } catch (InvalidArgumentException) {
                    continue;
                }

                if (! in_array($code, $defaultAllowed, true)) {
                    $defaultAllowed[] = $code;
                }
            }

            sort($defaultAllowed);

            if (count($defaultAllowed) === 1) {
                $defaultBase = $defaultAllowed[0];
                $defaultDisplay = $defaultAllowed[0];
                $defaultForceSingle = true;
            }
        }

        try {
            if ($model === null) {
                $base = $defaultBase;
                $display = $defaultDisplay;
                $allowed = $defaultAllowed;
                $forceSingle = $defaultForceSingle;
                $rounding = FinancialRoundingMode::HalfUp->value;
            } else {
                $base = $catalog->code($model->getRawOriginal('base_currency'))->value;
                $display = $catalog->code($model->getRawOriginal('display_currency'))->value;
                $allowed = DB::table('organization_allowed_currencies')
                    ->where('organization_id', $organizationId)
                    ->orderBy('currency')
                    ->pluck('currency')
                    ->map(fn (mixed $currency): string => $catalog->code($currency)->value)
                    ->values()
                    ->all();
                $forceSingle = filter_var($model->getRawOriginal('force_single_currency'), FILTER_VALIDATE_BOOLEAN);
                $rounding = FinancialRoundingMode::fromMixed($model->getRawOriginal('rounding_mode'))->value;

                if ($allowed === [] || ! in_array($base, $allowed, true) || ! in_array($display, $allowed, true)
                    || ($forceSingle && ($base !== $display || $allowed !== [$base]))) {
                    throw new InvalidArgumentException('The persisted currency configuration is invalid.');
                }
            }

            $rates = $this->persistedRates($organizationId, $catalog);
        } catch (InvalidArgumentException|UnexpectedValueException) {
            $this->configurationUnavailable = true;
            $this->data = null;

            return;
        }

        $this->form->fill([
            'base_currency' => $base,
            'display_currency' => $display,
            'allowed_currencies' => $allowed,
            'force_single_currency' => $forceSingle,
            'rounding_mode' => $rounding,
            'rates' => $rates,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основные настройки')
                    ->schema([
                        Select::make('base_currency')
                            ->label('Валюта практики')
                            ->options(fn (): array => app(CurrencyCatalog::class)->options())
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                if (! is_string($state) || $state === '') {
                                    return;
                                }

                                if ((bool) $get('force_single_currency')) {
                                    self::setSingleCurrencyState($set, $state);

                                    return;
                                }

                                self::normalizeMultiCurrencyState($get, $set);
                            })
                            ->disabled(fn (): bool => ! self::canManage())
                            ->required(),
                        Select::make('display_currency')
                            ->label('Валюта отображения')
                            ->helperText('В режиме одной валюты совпадает с валютой практики.')
                            ->options(fn (): array => app(CurrencyCatalog::class)->options())
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                if ((bool) $get('force_single_currency')) {
                                    $base = $get('base_currency');

                                    if (is_string($base) && $base !== '') {
                                        self::setSingleCurrencyState($set, $base);
                                    }

                                    return;
                                }

                                self::normalizeMultiCurrencyState($get, $set);
                            })
                            ->disabled(fn (Get $get): bool => ! self::canManage() || (bool) $get('force_single_currency'))
                            ->required(),
                        Toggle::make('force_single_currency')
                            ->label('Принимать оплаты только в одной валюте')
                            ->helperText('Для обычной практики оставьте включённым режим одной валюты.')
                            ->live()
                            ->disabled(fn (): bool => ! self::canManage())
                            ->afterStateUpdated(function (Get $get, Set $set, ?bool $state): void {
                                if (! $state) {
                                    self::normalizeMultiCurrencyState($get, $set, null, true);

                                    return;
                                }

                                $base = $get('base_currency');

                                if (is_string($base) && $base !== '') {
                                    self::setSingleCurrencyState($set, $base);
                                }
                            }),
                        Select::make('allowed_currencies')
                            ->label('Валюты, доступные для оплаты')
                            ->options(fn (): array => app(CurrencyCatalog::class)->options())
                            ->multiple()
                            ->searchable()
                            ->live()
                            ->required()
                            ->visible(fn (Get $get): bool => ! (bool) $get('force_single_currency'))
                            ->dehydrated(true)
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                if ((bool) $get('force_single_currency')) {
                                    $base = $get('base_currency');

                                    if (is_string($base) && $base !== '') {
                                        self::setSingleCurrencyState($set, $base);
                                    }

                                    return;
                                }

                                self::normalizeMultiCurrencyState($get, $set, $state);
                            })
                            ->disabled(fn (): bool => ! self::canManage()),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Мультивалютные расчёты')
                    ->description('Настройте дополнительные валюты и конвертацию только если практика принимает оплаты в нескольких валютах.')
                    ->schema([
                        Select::make('rounding_mode')
                            ->label('Правило округления при конвертации')
                            ->options([
                                FinancialRoundingMode::HalfUp->value => 'Обычное математическое',
                                FinancialRoundingMode::HalfEven->value => 'До ближайшего чётного',
                                FinancialRoundingMode::Down->value => 'Вниз, без увеличения суммы',
                            ])
                            ->required()
                            ->dehydrated(true)
                            ->disabled(fn (): bool => ! self::canManage()),
                        Repeater::make('rates')
                            ->label('Курсы конвертации')
                            ->helperText('Например: 1 USD = 500 KZT')
                            ->schema([
                                Select::make('source_currency')
                                    ->label('Из валюты')
                                    ->options(fn (Get $get): array => self::selectedCurrencyOptions($get))
                                    ->required()
                                    ->disabled(fn (): bool => ! self::canManage()),
                                Select::make('target_currency')
                                    ->label('В валюту')
                                    ->options(fn (Get $get): array => self::selectedCurrencyOptions($get))
                                    ->required()
                                    ->disabled(fn (): bool => ! self::canManage()),
                                TextInput::make('rate')
                                    ->label('Курс')
                                    ->placeholder('500')
                                    ->inputMode('decimal')
                                    ->required()
                                    ->maxLength(40)
                                    ->disabled(fn (): bool => ! self::canManage()),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Добавить курс')
                            ->columnSpanFull()
                            ->dehydrated(true)
                            ->disabled(fn (): bool => ! self::canManage()),
                    ])
                    ->visible(fn (Get $get): bool => ! (bool) $get('force_single_currency'))
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        if ($this->configurationUnavailable) {
            return $schema->components([
                Section::make('Настройки валют недоступны')
                    ->description('Сохранённые финансовые данные требуют проверки. Изменение настроек временно недоступно.')
                    ->schema([])
                    ->columnSpanFull(),
            ]);
        }

        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('finance-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Сохранить финансовые настройки')
                        ->visible(fn (): bool => self::canManage())
                        ->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        if ($this->configurationUnavailable) {
            throw ValidationException::withMessages([
                'currency' => 'Сохранённые финансовые данные требуют проверки. Настройки не изменены.',
            ]);
        }

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();

        if (filter_var($data['force_single_currency'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $base = $data['base_currency'] ?? null;

            if (is_string($base) && $base !== '') {
                $data['display_currency'] = $base;
                $data['allowed_currencies'] = [$base];
                $data['rates'] = [];
            }
        }

        try {
            app(SaveCurrencyConfiguration::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        Notification::make()->success()->title('Финансовые настройки сохранены')->send();
    }

    /** @return list<array{source_currency: string, target_currency: string, rate: string}> */
    private function persistedRates(int $organizationId, CurrencyCatalog $catalog): array
    {
        $seen = [];
        $rates = [];

        foreach (OrganizationExchangeRate::query()
            ->where('organization_id', $organizationId)
            ->orderBy('source_currency')
            ->orderBy('target_currency')
            ->get() as $rate) {
            $source = $catalog->code($rate->getRawOriginal('source_currency'))->value;
            $target = $catalog->code($rate->getRawOriginal('target_currency'))->value;
            $value = $rate->getRawOriginal('rate');

            if ((! is_string($value) && ! is_int($value) && ! is_float($value))
                || preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?$/', (string) $value) !== 1
                || BigDecimal::of((string) $value)->isNegativeOrZero()
                || $source === $target) {
                throw new InvalidArgumentException('A persisted exchange rate is invalid.');
            }

            $key = $source.'>'.$target;

            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Persisted exchange rates are duplicated after normalization.');
            }

            $seen[$key] = true;
            $rates[] = [
                'source_currency' => $source,
                'target_currency' => $target,
                'rate' => (string) $value,
            ];
        }

        return $rates;
    }

    /** @return array<string, string> */
    private static function selectedCurrencyOptions(Get $get): array
    {
        $selected = $get('../../allowed_currencies');
        $options = app(CurrencyCatalog::class)->options();

        if (! is_array($selected) || $selected === []) {
            return $options;
        }

        return array_intersect_key($options, array_flip(array_map('strval', $selected)));
    }

    private static function setSingleCurrencyState(Set $set, string $base): void
    {
        $set('display_currency', $base);
        $set('allowed_currencies', [$base]);
    }

    private static function normalizeMultiCurrencyState(
        Get $get,
        Set $set,
        mixed $changedAllowed = null,
        bool $restoreRateCurrencies = false,
    ): void {
        $base = $get('base_currency');

        if (! is_string($base) || $base === '') {
            return;
        }

        $selected = $changedAllowed ?? $get('allowed_currencies');
        $allowed = is_array($selected)
            ? array_values(array_unique(array_filter(array_map('strval', $selected), static fn (string $currency): bool => $currency !== '')))
            : [];
        $display = $get('display_currency');

        if (! in_array($base, $allowed, true)) {
            $allowed[] = $base;
        }

        if (! is_string($display) || $display === '') {
            $display = $base;
        }

        if (! in_array($display, $allowed, true)) {
            $allowed[] = $display;
        }

        if ($restoreRateCurrencies) {
            $rates = $get('rates');

            if (is_array($rates)) {
                foreach ($rates as $rate) {
                    if (! is_array($rate)) {
                        continue;
                    }

                    foreach (['source_currency', 'target_currency'] as $currencyKey) {
                        $currency = $rate[$currencyKey] ?? null;

                        if (is_string($currency) && $currency !== '') {
                            $allowed[] = $currency;
                        }
                    }
                }
            }
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);
        $set('display_currency', $display);
        $set('allowed_currencies', $allowed);
    }
}
