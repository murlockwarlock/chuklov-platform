<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Finance\Application\CurrentCurrencyConfigurationIntegrity;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SaveLavaConfiguration;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
final class FinanceConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Настройки валют';

    protected static ?string $navigationLabel = 'Настройки валют';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public bool $configurationUnavailable = false;

    public string $lavaStatus = '';

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
        $this->lavaStatus = __('Не подключена');

        $organizationId = app(OrganizationContext::class)->id();
        $catalog = app(CurrencyCatalog::class);
        $integrity = app(CurrentCurrencyConfigurationIntegrity::class);
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
            $current = $integrity->inspect($model, $organizationId);

            if ($current === null) {
                $base = $defaultBase;
                $display = $defaultDisplay;
                $allowed = $defaultAllowed;
                $forceSingle = $defaultForceSingle;
                $rounding = FinancialRoundingMode::HalfUp->value;
                $rates = [];
            } else {
                $base = $current['base']->value;
                $display = $current['display']->value;
                $allowed = array_map(
                    static fn ($currency): string => $currency->value,
                    $current['allowed'],
                );
                $forceSingle = $current['force_single'];
                $rounding = $current['rounding']->value;
                $rates = array_map(
                    static fn (array $rate): array => [
                        'source_currency' => $rate['source']->value,
                        'target_currency' => $rate['target']->value,
                        'rate' => $rate['rate'],
                    ],
                    $current['rates'],
                );
            }
        } catch (InvalidArgumentException|UnexpectedValueException) {
            $this->configurationUnavailable = true;
            $this->data = null;

            return;
        }

        $lavaCredential = $this->lavaCredential($organizationId);
        $this->lavaStatus = $this->lavaStatusLabel($lavaCredential);

        $this->form->fill([
            'base_currency' => $base,
            'display_currency' => $display,
            'allowed_currencies' => $allowed,
            'force_single_currency' => $forceSingle,
            'rounding_mode' => $rounding,
            'rates' => $rates,
            'lava_enabled' => $lavaCredential?->status === CredentialStatus::Active,
            'lava_api_key' => null,
            'lava_webhook_key' => null,
            'lava_webhook_url' => route('webhooks.lava'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Основные настройки'))
                    ->schema([
                        Select::make('base_currency')
                            ->label(__('Валюта практики'))
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
                            ->label(__('Валюта отображения'))
                            ->helperText(__('В режиме одной валюты совпадает с валютой практики.'))
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
                            ->label(__('Принимать оплаты только в одной валюте'))
                            ->helperText(__('Для обычной практики оставьте включённым режим одной валюты.'))
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
                            ->label(__('Валюты, доступные для оплаты'))
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

                Section::make(__('Мультивалютные расчёты'))
                    ->description(__('Настройте дополнительные валюты и конвертацию только если практика принимает оплаты в нескольких валютах.'))
                    ->schema([
                        Select::make('rounding_mode')
                            ->label(__('Правило округления при конвертации'))
                            ->options([
                                FinancialRoundingMode::HalfUp->value => __('Обычное математическое'),
                                FinancialRoundingMode::HalfEven->value => __('До ближайшего чётного'),
                                FinancialRoundingMode::Down->value => __('Вниз, без увеличения суммы'),
                            ])
                            ->required()
                            ->dehydrated(true)
                            ->disabled(fn (): bool => ! self::canManage()),
                        Repeater::make('rates')
                            ->label(__('Курсы конвертации'))
                            ->helperText(__('Например: 1 USD = 500 KZT'))
                            ->schema([
                                Select::make('source_currency')
                                    ->label(__('Из валюты'))
                                    ->options(fn (Get $get): array => self::selectedCurrencyOptions($get))
                                    ->required()
                                    ->disabled(fn (): bool => ! self::canManage()),
                                Select::make('target_currency')
                                    ->label(__('В валюту'))
                                    ->options(fn (Get $get): array => self::selectedCurrencyOptions($get))
                                    ->required()
                                    ->disabled(fn (): bool => ! self::canManage()),
                                TextInput::make('rate')
                                    ->label(__('Курс'))
                                    ->placeholder('500')
                                    ->inputMode('decimal')
                                    ->required()
                                    ->maxLength(40)
                                    ->disabled(fn (): bool => ! self::canManage()),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('Добавить курс'))
                            ->columnSpanFull()
                            ->dehydrated(true)
                            ->disabled(fn (): bool => ! self::canManage()),
                    ])
                    ->visible(fn (Get $get): bool => ! (bool) $get('force_single_currency'))
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Платёжные системы'))
                    ->schema([
                        Section::make('Lava')
                            ->description(__('Подключите Lava для разовой онлайн-оплаты. Сохранённые ключи не отображаются обратно в форме.'))
                            ->schema([
                                Placeholder::make('lava_status')
                                    ->label(__('Статус'))
                                    ->content(fn (): string => $this->lavaStatus),
                                Toggle::make('lava_enabled')
                                    ->label(__('Lava активна'))
                                    ->helperText(__('Отключение сохраняет историю платежей и только прекращает новые обращения к Lava.'))
                                    ->disabled(fn (): bool => ! self::canManage()),
                                TextInput::make('lava_api_key')
                                    ->label(__('API-ключ'))
                                    ->password()
                                    ->revealable()
                                    ->autocomplete('new-password')
                                    ->maxLength(2048)
                                    ->nullable()
                                    ->dehydrated(fn (mixed $state): bool => filled($state))
                                    ->disabled(fn (): bool => ! self::canManage())
                                    ->helperText(__('Оставьте пустым, чтобы сохранить текущий ключ.')),
                                TextInput::make('lava_webhook_key')
                                    ->label(__('Ключ для webhook'))
                                    ->password()
                                    ->revealable()
                                    ->autocomplete('new-password')
                                    ->maxLength(2048)
                                    ->nullable()
                                    ->dehydrated(fn (mixed $state): bool => filled($state))
                                    ->disabled(fn (): bool => ! self::canManage())
                                    ->helperText(__('Оставьте пустым, чтобы сохранить текущий ключ. Если отдельный ключ не задан, используется API-ключ.')),
                                TextInput::make('lava_webhook_url')
                                    ->label('Webhook URL')
                                    ->default(fn (): string => route('webhooks.lava'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText(__('Укажите этот адрес в настройках webhook Lava.'))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        if ($this->configurationUnavailable) {
            return $schema->components([
                Section::make(__('Настройки валют недоступны'))
                    ->description(__('Сохранённые финансовые данные требуют проверки. Изменение настроек временно недоступно.'))
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
                        ->label(__('Сохранить финансовые настройки'))
                        ->visible(fn (): bool => self::canManage())
                        ->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        if ($this->configurationUnavailable) {
            throw ValidationException::withMessages([
                'currency' => __('Сохранённые финансовые данные требуют проверки. Настройки не изменены.'),
            ]);
        }

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $this->resetErrorBag();
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
            DB::transaction(function () use ($actor, $data): void {
                app(SaveCurrencyConfiguration::class)->handle($actor, $data);
                app(SaveLavaConfiguration::class)->handle(
                    actor: $actor,
                    apiKey: isset($data['lava_api_key']) && is_string($data['lava_api_key']) ? $data['lava_api_key'] : null,
                    webhookKey: isset($data['lava_webhook_key']) && is_string($data['lava_webhook_key']) ? $data['lava_webhook_key'] : null,
                    enabled: filter_var($data['lava_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                );
            });
            $this->lavaStatus = $this->lavaStatusLabel($this->lavaCredential(app(OrganizationContext::class)->id()));
        } catch (ValidationException $exception) {
            $firstMessage = null;

            foreach ($exception->errors() as $field => $messages) {
                $field = str_starts_with($field, 'data.') ? $field : "data.{$field}";

                foreach ($messages as $message) {
                    $this->addError($field, $message);
                    $firstMessage ??= $message;
                }
            }

            Notification::make()
                ->danger()
                ->title(__('Не удалось сохранить финансовые настройки'))
                ->body($firstMessage ?? __('Проверьте заполнение полей.'))
                ->send();

            return;
        }

        Notification::make()->success()->title(__('Финансовые настройки сохранены'))->send();
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

    private function lavaCredential(int $organizationId): ?OrganizationCredential
    {
        return OrganizationCredential::query()
            ->where('organization_id', $organizationId)
            ->where('provider', 'lava')
            ->where('credential_name', (string) config('payments.lava.credential_name', 'default'))
            ->first();
    }

    private function lavaStatusLabel(?OrganizationCredential $credential): string
    {
        if ($credential === null || $credential->status !== CredentialStatus::Active) {
            return __('Не подключена');
        }

        $apiKey = $credential->credentials['api_key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== ''
            ? __('Подключена')
            : __('Ошибка настройки');
    }
}
