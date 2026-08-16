<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\OrganizationExchangeRate;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class FinanceConfiguration extends Page
{
    protected static ?string $navigationLabel = 'Настройки финансов';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

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

    public function mount(): void
    {
        $organizationId = app(OrganizationContext::class)->id();
        $configuration = app(CurrencyConfigurationService::class);
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
                } catch (\InvalidArgumentException) {
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

        $allowed = $model === null ? $defaultAllowed : array_map(
            static fn ($currency): string => $currency->value,
            $configuration->allowedCurrencies($organizationId),
        );

        $this->form->fill([
            'base_currency' => $model === null ? $defaultBase : $model->base_currency->value,
            'display_currency' => $model === null ? $defaultDisplay : $model->display_currency->value,
            'allowed_currencies' => $allowed,
            'force_single_currency' => $model === null ? $defaultForceSingle : $model->force_single_currency,
            'rounding_mode' => $model === null ? FinancialRoundingMode::HalfUp->value : $model->rounding_mode->value,
            'rates' => OrganizationExchangeRate::query()
                ->where('organization_id', $organizationId)
                ->orderBy('source_currency')
                ->orderBy('target_currency')
                ->get()
                ->map(fn (OrganizationExchangeRate $rate): array => [
                    'source_currency' => $rate->source_currency->value,
                    'target_currency' => $rate->target_currency->value,
                    'rate' => (string) $rate->getRawOriginal('rate'),
                ])
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('base_currency')
                    ->label('Базовая валюта')
                    ->options(fn (): array => app(CurrencyCatalog::class)->options())
                    ->required(),
                Select::make('display_currency')
                    ->label('Валюта отображения')
                    ->options(fn (): array => app(CurrencyCatalog::class)->options())
                    ->required(),
                Select::make('allowed_currencies')
                    ->label('Доступные валюты')
                    ->options(fn (): array => app(CurrencyCatalog::class)->options())
                    ->multiple()
                    ->searchable()
                    ->required(),
                Toggle::make('force_single_currency')
                    ->label('Работать только в одной валюте')
                    ->helperText('В этом режиме базовая и отображаемая валюта должны совпадать.')
                    ->live(),
                Select::make('rounding_mode')
                    ->label('Округление при конвертации')
                    ->options([
                        FinancialRoundingMode::HalfUp->value => 'Обычное математическое',
                        FinancialRoundingMode::HalfEven->value => 'До ближайшего чётного',
                        FinancialRoundingMode::Down->value => 'Вниз, без увеличения суммы',
                    ])
                    ->required(),
                Repeater::make('rates')
                    ->label('Ручные курсы')
                    ->schema([
                        Select::make('source_currency')
                            ->label('Из валюты')
                            ->options(fn (Get $get): array => self::selectedCurrencyOptions($get))
                            ->required(),
                        Select::make('target_currency')
                            ->label('В валюту')
                            ->options(fn (Get $get): array => self::selectedCurrencyOptions($get))
                            ->required(),
                        TextInput::make('rate')
                            ->label('Курс: 1 единица =')
                            ->inputMode('decimal')
                            ->required()
                            ->maxLength(40),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('Добавить курс')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
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
                        ->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();

        try {
            app(SaveCurrencyConfiguration::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        Notification::make()->success()->title('Финансовые настройки сохранены')->send();
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
}
