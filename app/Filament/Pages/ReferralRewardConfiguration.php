<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Application\GetReferralRewardProgram;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
use App\Modules\Referrals\Domain\Enums\ReferralRewardFormula;
use App\Modules\Referrals\Domain\Enums\ReferralRewardQualificationRule;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class ReferralRewardConfiguration extends Page
{
    protected static ?string $title = 'Реферальная программа';

    protected static ?string $navigationLabel = 'Реферальная программа';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Партнёры';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public string $programSummary = '';

    public string $programExample = '';

    public string $programEffectiveAt = '';

    protected string $view = 'filament.pages.referral-reward-configuration';

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
        $program = app(GetReferralRewardProgram::class)->handle();
        $this->setProgramPresentation($program);
        $this->form->fill([
            'enabled' => $program['enabled'],
            'qualification_rule' => $program['qualificationRule'],
            'formula' => $program['formula'],
            'fixed_amount' => $program['fixedAmount'],
            'fixed_currency' => $program['fixedCurrency'],
            'percentage' => $program['percentage'],
            'effective_at' => $program['effectiveAt'] === null
                ? now()->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                : Carbon::parse($program['effectiveAt'])->setTimezone(app(OrganizationContext::class)->defaultTimezone()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Как сейчас работает')
                    ->schema([
                        Placeholder::make('program_summary')
                            ->label('Текущие условия')
                            ->content(fn (): string => $this->programSummary),
                        Placeholder::make('program_example')
                            ->label('Пример расчёта')
                            ->content(fn (): string => $this->programExample),
                        Placeholder::make('program_scope')
                            ->label('Общие и индивидуальные условия')
                            ->content('Общие условия действуют по умолчанию. Индивидуальные условия партнёра, если они заданы, заменяют общие для этого партнёра.'),
                        Placeholder::make('program_effective_at')
                            ->label('Дата начала действия')
                            ->content(fn (): string => $this->programEffectiveAt),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Реферальная программа')
                    ->description('Начисление выключено по умолчанию. Каждое сохранение создаёт новую версию, а история начислений не изменяется.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Включена')
                            ->live()
                            ->inline()
                            ->columnSpanFull()
                            ->disabled(fn (): bool => ! self::canManage()),
                        Grid::make(2)
                            ->schema([
                                Select::make('qualification_rule')
                                    ->label('Начислять')
                                    ->options([
                                        ReferralRewardQualificationRule::FirstSettledPayment->value => ReferralRewardQualificationRule::FirstSettledPayment->label(),
                                        ReferralRewardQualificationRule::EverySettledPayment->value => ReferralRewardQualificationRule::EverySettledPayment->label(),
                                    ])
                                    ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                                    ->required(fn (Get $get): bool => (bool) $get('enabled'))
                                    ->disabled(fn (): bool => ! self::canManage()),
                                Select::make('formula')
                                    ->label('Размер бонуса')
                                    ->options([
                                        ReferralRewardFormula::FixedAmount->value => ReferralRewardFormula::FixedAmount->label(),
                                        ReferralRewardFormula::PercentageOfSettlement->value => ReferralRewardFormula::PercentageOfSettlement->label(),
                                    ])
                                    ->live()
                                    ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                                    ->required(fn (Get $get): bool => (bool) $get('enabled'))
                                    ->disabled(fn (): bool => ! self::canManage()),
                                TextInput::make('fixed_amount')
                                    ->label('Фиксированная сумма')
                                    ->inputMode('decimal')
                                    ->placeholder('Укажите сумму')
                                    ->regex('/^(?:0|[1-9][0-9]{0,18})(?:\.[0-9]{1,2})?$/')
                                    ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value)
                                    ->required(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value)
                                    ->disabled(fn (): bool => ! self::canManage()),
                                Select::make('fixed_currency')
                                    ->label('Валюта фиксированной суммы')
                                    ->options(fn (): array => app(CurrencyCatalog::class)->options())
                                    ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value)
                                    ->required(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value)
                                    ->disabled(fn (): bool => ! self::canManage()),
                                TextInput::make('percentage')
                                    ->label('Процент от оплаты')
                                    ->inputMode('decimal')
                                    ->placeholder('Укажите процент')
                                    ->suffix('%')
                                    ->regex('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/')
                                    ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::PercentageOfSettlement->value)
                                    ->required(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::PercentageOfSettlement->value)
                                    ->disabled(fn (): bool => ! self::canManage()),
                                DateTimePicker::make('effective_at')
                                    ->label('Дата начала действия')
                                    ->helperText('Оплата, подтверждённая раньше этой даты, не использует эту версию правила.')
                                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                                    ->seconds(false)
                                    ->required()
                                    ->disabled(fn (): bool => ! self::canManage()),
                            ])
                            ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->compact()
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
            ->id('referral-reward-configuration-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Сохранить настройки')
                        ->visible(fn (): bool => self::canManage())
                        ->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        app(SaveReferralRewardProgram::class)->handle(
            actor: $actor,
            enabled: (bool) ($data['enabled'] ?? false),
            qualificationRule: is_string($data['qualification_rule'] ?? null) ? $data['qualification_rule'] : null,
            formula: is_string($data['formula'] ?? null) ? $data['formula'] : null,
            fixedAmount: is_string($data['fixed_amount'] ?? null) ? $data['fixed_amount'] : null,
            fixedCurrency: is_string($data['fixed_currency'] ?? null) ? $data['fixed_currency'] : null,
            percentage: is_string($data['percentage'] ?? null) ? $data['percentage'] : null,
            effectiveAt: $data['effective_at'] ?? null,
        );
        $this->setProgramPresentation(app(GetReferralRewardProgram::class)->handle());
        Notification::make()->success()->title('Реферальная программа сохранена')->send();
    }

    /** @param array<string, mixed> $program */
    private function setProgramPresentation(array $program): void
    {
        $this->programSummary = $this->summary($program);
        $this->programExample = $this->example($program);
        $this->programEffectiveAt = $this->effectiveAtLabel($program['effectiveAt'] ?? null);
    }

    /** @param array<string, mixed> $program */
    private function summary(array $program): string
    {
        if (! (bool) ($program['enabled'] ?? false)) {
            return 'Программа выключена: начисления не создаются.';
        }

        $qualification = ReferralRewardQualificationRule::tryFrom((string) ($program['qualificationRule'] ?? ''))?->label() ?? 'Правило начисления не указано';
        $formula = ReferralRewardFormula::tryFrom((string) ($program['formula'] ?? ''));
        $reward = $formula === ReferralRewardFormula::FixedAmount
            ? 'фиксированная сумма '.self::amountLabel($program['fixedAmount'] ?? null, $program['fixedCurrency'] ?? null)
            : 'процент '.((string) ($program['percentage'] ?? '—')).'% от оплаты';

        return 'Программа включена. '.$qualification.'. Размер бонуса: '.$reward.'.';
    }

    /** @param array<string, mixed> $program */
    private function example(array $program): string
    {
        if (! (bool) ($program['enabled'] ?? false)) {
            return 'Включите программу и сохраните настройки, чтобы начисления стали возможны.';
        }

        $formula = ReferralRewardFormula::tryFrom((string) ($program['formula'] ?? ''));
        if ($formula === ReferralRewardFormula::FixedAmount) {
            return 'После подтверждённой оплаты партнёру будет начислено '.self::amountLabel($program['fixedAmount'] ?? null, $program['fixedCurrency'] ?? null).'.';
        }

        $currency = $this->organizationDisplayCurrency();
        $percentage = (float) ($program['percentage'] ?? 0);
        $reward = self::numberLabel(100000 * $percentage / 100);

        return $currency === null
            ? 'Пример недоступен: сначала настройте валюту организации.'
            : 'Если клиент оплатил '.self::numberLabel(100000).' '.$currency.', партнёру будет начислено '.$reward.' '.$currency.'.';
    }

    private function effectiveAtLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'Дата начала пока не задана.';
        }

        return 'Версия действует с '.Carbon::parse($value)
            ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
            ->format('d.m.Y H:i').' ('.app(OrganizationContext::class)->defaultTimezone().').';
    }

    private function organizationDisplayCurrency(): ?string
    {
        try {
            return app(CurrencyConfigurationService::class)
                ->configuration(app(OrganizationContext::class)->id())
                ->display_currency
                ?->value;
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private static function amountLabel(mixed $amount, mixed $currency): string
    {
        if (! is_string($amount) || $amount === '' || ! is_string($currency) || $currency === '') {
            return 'сумма не указана';
        }

        return self::numberLabel((float) $amount).' '.$currency;
    }

    private static function numberLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ' '), '0'), '.');
    }
}
