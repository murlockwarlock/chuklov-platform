<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Pages;

use App\Filament\Pages\ReferralRewardConfiguration;
use App\Filament\Resources\ReferralPartnerProfiles\ReferralPartnerProfileResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedViewRecord;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\CreditManualReferralBonus;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralPartner;
use App\Modules\Referrals\Application\GetReferralPartnerWorkspace;
use App\Modules\Referrals\Application\GetReferralRewardProgram;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Enums\ReferralRewardFormula;
use App\Modules\Referrals\Domain\Enums\ReferralRewardQualificationRule;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

final class ViewReferralPartnerProfile extends LocalizedViewRecord
{
    protected static string $resource = ReferralPartnerProfileResource::class;

    protected static ?string $title = 'Партнёрский кабинет';

    protected string $view = 'filament.resources.referral-partner-profiles.pages.view';

    public int $workspacePollingStartedAt = 0;

    /** @var array<string, mixed>|null */
    private ?array $workspace = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->workspacePollingStartedAt = now()->getTimestamp();
    }

    public function refreshWorkspace(): void
    {
        $this->workspace = null;
        $this->getRecord()->refresh();
    }

    public function shouldPollWorkspace(): bool
    {
        return $this->workspacePollingStartedAt > 0
            && now()->getTimestamp() < $this->workspacePollingStartedAt + 120;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openClient')
                ->label(__('Открыть клиента'))
                ->icon('heroicon-o-user')
                ->extraAttributes(['data-testid' => 'partner-primary-open-client'])
                ->url(fn (): ?string => CrmEntityLinks::clientUrl($this->partnerProfile()->client))
                ->visible(fn (): bool => $this->canViewClient()),
            Action::make('createCampaignLink')
                ->label(__('Создать ссылку'))
                ->icon('heroicon-o-plus')
                ->extraAttributes(['data-testid' => 'partner-primary-create-link'])
                ->schema([
                    TextInput::make('name')
                        ->label(__('Название'))
                        ->placeholder(__('Instagram — шапка профиля'))
                        ->required()
                        ->maxLength(180),
                    Select::make('channel')
                        ->label(__('Канал'))
                        ->options(ReferralCampaignChannel::options())
                        ->native(false)
                        ->required(),
                ])
                ->modalSubmitActionLabel(__('Создать'))
                ->visible(fn (): bool => $this->partnerProfile()->isActive() && $this->canManageClients())
                ->action(function (array $data): void {
                    app(CreateReferralCampaignLink::class)->handle(
                        client: $this->partnerClient(),
                        name: (string) $data['name'],
                        channel: ReferralCampaignChannel::from((string) $data['channel']),
                        actor: $this->actor(),
                    );
                    $this->workspace = null;
                    Notification::make()->title(__('Реферальная ссылка создана'))->success()->send();
                }),
            Action::make('manualBonus')
                ->label(__('Начислить бонус'))
                ->icon('heroicon-o-plus-circle')
                ->extraAttributes(['data-testid' => 'partner-primary-credit-bonus'])
                ->schema([
                    TextInput::make('amount')
                        ->label(__('Сумма'))
                        ->inputMode('decimal')
                        ->required()
                        ->maxLength(24),
                    Select::make('currency')
                        ->label(__('Валюта'))
                        ->options(fn (): array => app(CurrencyCatalog::class)->options())
                        ->native(false)
                        ->required(),
                    Textarea::make('reason')
                        ->label(__('Причина'))
                        ->required()
                        ->maxLength(500)
                        ->rows(3),
                    Textarea::make('comment')
                        ->label(__('Комментарий'))
                        ->maxLength(1000)
                        ->rows(3),
                    Hidden::make('idempotency_key')
                        ->default(fn (): string => 'manual-bonus-'.Str::uuid()),
                ])
                ->modalSubmitActionLabel(__('Начислить бонус'))
                ->visible(fn (): bool => $this->canManageRewards())
                ->action(function (array $data): void {
                    app(CreditManualReferralBonus::class)->handle(
                        actor: $this->actor(),
                        partner: $this->partnerProfile(),
                        amount: (string) ($data['amount'] ?? ''),
                        currency: (string) ($data['currency'] ?? ''),
                        reason: (string) ($data['reason'] ?? ''),
                        comment: is_string($data['comment'] ?? null) ? $data['comment'] : null,
                        idempotencyKey: (string) ($data['idempotency_key'] ?? ''),
                    );
                    $this->workspace = null;
                    Notification::make()->title(__('Бонус начислен'))->success()->send();
                }),
            ActionGroup::make([
                Action::make('rewardTerms')
                    ->label(__('Настроить индивидуально'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema(self::rewardTermsSchema())
                    ->fillForm(fn (): array => $this->rewardTermsForm())
                    ->modalHeading(__('Индивидуальные условия'))
                    ->modalSubmitActionLabel(__('Сохранить условия'))
                    ->visible(fn (): bool => $this->canManageRewards())
                    ->action(function (array $data): void {
                        app(SaveReferralRewardProgram::class)->handle(
                            actor: $this->actor(),
                            enabled: (bool) ($data['enabled'] ?? false),
                            qualificationRule: is_string($data['qualification_rule'] ?? null) ? $data['qualification_rule'] : null,
                            formula: is_string($data['formula'] ?? null) ? $data['formula'] : null,
                            fixedAmount: is_string($data['fixed_amount'] ?? null) ? $data['fixed_amount'] : null,
                            fixedCurrency: is_string($data['fixed_currency'] ?? null) ? $data['fixed_currency'] : null,
                            percentage: is_string($data['percentage'] ?? null) ? $data['percentage'] : null,
                            effectiveAt: $data['effective_at'] ?? null,
                            partnerProfile: $this->partnerProfile(),
                        );
                        $this->workspace = null;
                        Notification::make()->title(__('Индивидуальные условия сохранены'))->success()->send();
                    }),
                Action::make('resetRewardTerms')
                    ->label(__('Вернуть общие условия'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canManageRewards() && $this->rewardTermsForm()['isOverride'])
                    ->action(function (): void {
                        app(SaveReferralRewardProgram::class)->handle(
                            actor: $this->actor(),
                            enabled: false,
                            qualificationRule: null,
                            formula: null,
                            fixedAmount: null,
                            fixedCurrency: null,
                            percentage: null,
                            effectiveAt: now(),
                            partnerProfile: $this->partnerProfile(),
                        );
                        $this->workspace = null;
                        Notification::make()->title(__('Общие условия восстановлены'))->success()->send();
                    }),
                Action::make('editDefaultRewardTerms')
                    ->label(__('Изменить общие условия'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn (): string => ReferralRewardConfiguration::getUrl())
                    ->visible(fn (): bool => $this->canManageRewards()),
                Action::make('disableCampaignLink')
                    ->label(__('Отключить ссылку'))
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->schema([
                        Select::make('campaign_link_id')
                            ->label(__('Ссылка'))
                            ->options(fn (): array => $this->partnerProfile()
                                ->activeCampaignLinks()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($link): array => [$link->getKey() => $link->name.' · '.(CrmLabel::enum(ReferralCampaignChannel::tryFrom((string) $link->getRawOriginal('channel'))) ?? __('Другое'))])
                                ->all())
                            ->native(false)
                            ->required(),
                    ])
                    ->visible(fn (): bool => $this->partnerProfile()->isActive()
                        && $this->partnerProfile()->activeCampaignLinks()->exists()
                        && $this->canManageClients())
                    ->action(function (array $data): void {
                        app(DeactivateReferralCampaignLink::class)->handle(
                            link: (int) $data['campaign_link_id'],
                            actor: $this->actor(),
                        );
                        $this->workspace = null;
                        Notification::make()->title(__('Реферальная ссылка отключена'))->success()->send();
                    }),
                Action::make('activatePartner')
                    ->label(__('Активировать партнёра'))
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (): bool => ! $this->partnerProfile()->isActive() && $this->canManageClients())
                    ->action(function (): void {
                        app(ActivateReferralPartner::class)->handle($this->partnerClient(), 'crm', $this->actor());
                        $this->workspace = null;
                        Notification::make()->title(__('Партнёрская программа активирована'))->success()->send();
                    }),
                Action::make('deactivatePartner')
                    ->label(__('Отключить партнёрскую программу'))
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->partnerProfile()->isActive() && $this->canManageClients())
                    ->action(function (): void {
                        app(DeactivateReferralPartner::class)->handle($this->partnerClient(), $this->actor());
                        $this->workspace = null;
                        Notification::make()->title(__('Партнёрская программа отключена'))->success()->send();
                    }),
            ])
                ->label(__('Ещё'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->color('gray')
                ->dropdownPlacement('bottom-end')
                ->dropdownTeleport(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function workspaceLinkItems(): array
    {
        $links = $this->workspace()['overview']['links'] ?? [];
        if (! is_array($links)) {
            return [];
        }

        $items = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $rewardItems = $link['rewards'] ?? [];
            $items[] = [
                'name' => (string) ($link['name'] ?? __('Ссылка')),
                'channel' => self::referralChannelLabel($link['channel'] ?? null),
                'shareUrl' => (string) ($link['shareUrl'] ?? ''),
                'visits' => (int) ($link['visits'] ?? 0),
                'registrations' => (int) ($link['registrations'] ?? 0),
                'paidClients' => (int) ($link['paidClients'] ?? 0),
                'rewards' => is_array($rewardItems) ? $this->formatMoneyList($rewardItems) : '—',
            ];
        }

        return $items;
    }

    public function workspaceSummary(): string
    {
        $overview = $this->workspace()['overview'] ?? [];
        if (! is_array($overview)) {
            return '';
        }

        $stats = $overview['stats'] ?? [];
        $rewards = $overview['rewards'] ?? [];
        $balances = is_array($rewards) && is_array($rewards['balances'] ?? null)
            ? $rewards['balances']
            : [];
        $available = [];
        $pending = [];
        $paid = [];

        foreach ($balances as $balance) {
            if (! is_array($balance)) {
                continue;
            }

            $currency = (string) ($balance['currency'] ?? '');
            if ($currency === '') {
                continue;
            }

            $available[] = $this->formatMoney((string) ($balance['availableMinor'] ?? 0), $currency);
            $pending[] = $this->formatMoney((string) ($balance['pendingPayoutMinor'] ?? 0), $currency);
            $paid[] = $this->formatMoney((string) ($balance['paidOutMinor'] ?? 0), $currency);
        }

        return implode("\n", [
            __('Переходы: :count', ['count' => (int) ($stats['visits'] ?? 0)]),
            __('Регистрации: :count', ['count' => (int) ($stats['registrations'] ?? 0)]),
            __('Оплатили: :count', ['count' => (int) ($stats['paidClients'] ?? 0)]),
            __('Доступно: :amounts', ['amounts' => $available === [] ? '—' : implode(' · ', $available)]),
            __('Ожидает выплаты: :amounts', ['amounts' => $pending === [] ? '—' : implode(' · ', $pending)]),
            __('Выплачено: :amounts', ['amounts' => $paid === [] ? '—' : implode(' · ', $paid)]),
        ]);
    }

    public function rewardTermsSummary(): string
    {
        $terms = app(GetReferralRewardProgram::class)->handle($this->partnerProfile());
        $source = $terms['isOverride'] ? __('Индивидуальные условия') : __('Общие условия');
        $defaultTerms = is_array($terms['defaultTerms'] ?? null) ? $terms['defaultTerms'] : [];
        $lines = [
            __('Используются: :source', ['source' => $source]),
            self::rewardTermsLine($defaultTerms, __('Общие условия')),
        ];

        if (is_array($terms['overrideTerms'] ?? null)) {
            $lines[] = self::rewardTermsLine($terms['overrideTerms'], __('Индивидуальные условия'));
        }

        return implode("\n", $lines);
    }

    /** @return list<array<string, string>> */
    public function workspaceClientItems(): array
    {
        $registrations = $this->workspace()['overview']['registrations'] ?? [];
        if (! is_array($registrations)) {
            return [];
        }

        $items = [];
        foreach ($registrations as $client) {
            if (! is_array($client)) {
                continue;
            }

            $items[] = [
                'name' => (string) ($client['name'] ?? '—'),
                'registeredAt' => $client['registeredAt'] === null
                    ? __('Дата не указана')
                    : CarbonImmutable::parse((string) $client['registeredAt'])->format('d.m.Y H:i'),
                'origin' => self::referralSourceName($client['linkName'] ?? null).' / '.self::referralChannelLabel($client['channel'] ?? null),
                'paymentStatus' => ($client['paidClient'] ?? false) ? __('Оплатил') : __('Не оплатил'),
            ];
        }

        return $items;
    }

    /** @return list<array<string, string>> */
    public function workspaceRewardItems(): array
    {
        $history = $this->workspace()['overview']['rewards']['history'] ?? [];
        if (! is_array($history)) {
            return [];
        }

        $items = [];
        foreach ($history as $reward) {
            if (! is_array($reward)) {
                continue;
            }

            $items[] = [
                'type' => self::referralRewardTypeLabel($reward['typeLabel'] ?? null),
                'amount' => $this->formatMoney((string) ($reward['amountMinor'] ?? 0), (string) ($reward['currency'] ?? '')),
                'client' => (string) ($reward['clientName'] ?? __('Клиент не указан')),
                'reason' => (string) ($reward['reason'] ?? ''),
                'occurredAt' => CarbonImmutable::parse((string) ($reward['occurredAt'] ?? now()))->format('d.m.Y H:i'),
            ];
        }

        return $items;
    }

    /** @return list<array<string, string>> */
    public function workspacePayoutItems(): array
    {
        $payouts = $this->workspace()['overview']['rewards']['payouts'] ?? [];
        if (! is_array($payouts)) {
            return [];
        }

        $items = [];
        foreach ($payouts as $payout) {
            if (! is_array($payout)) {
                continue;
            }

            $items[] = [
                'amount' => $this->formatMoney((string) ($payout['amountMinor'] ?? 0), (string) ($payout['currency'] ?? '')),
                'status' => self::referralPayoutStatusLabel($payout['statusLabel'] ?? null),
                'requestedAt' => CarbonImmutable::parse((string) ($payout['requestedAt'] ?? now()))->format('d.m.Y H:i'),
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function workspace(): array
    {
        if ($this->workspace !== null) {
            return $this->workspace;
        }

        $this->workspace = app(GetReferralPartnerWorkspace::class)->handle($this->actor(), $this->partnerProfile());

        return $this->workspace;
    }

    private function partnerProfile(): ReferralPartnerProfile
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ReferralPartnerProfile, 404);

        return $record;
    }

    private function partnerClient(): Client
    {
        $client = $this->partnerProfile()->client;
        abort_unless($client instanceof Client, 404);

        return $client;
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function canViewClient(): bool
    {
        return CrmEntityLinks::clientUrl($this->partnerProfile()->client) !== null;
    }

    private function canManageClients(): bool
    {
        return app(OrganizationAuthorizer::class)->allows(
            $this->actor(),
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageClients,
        );
    }

    private function canManageRewards(): bool
    {
        return app(FinanceAuthorization::class)->allowsManage($this->actor());
    }

    private static function rewardTermsLine(array $terms, string $scope): string
    {
        if (! ($terms['enabled'] ?? false)) {
            return __(':scope: начисление отключено', ['scope' => $scope]);
        }

        $qualification = match ($terms['qualificationRule'] ?? null) {
            ReferralRewardQualificationRule::FirstSettledPayment->value => __('После первой подтверждённой оплаты'),
            ReferralRewardQualificationRule::EverySettledPayment->value => __('После каждой подтверждённой оплаты'),
            default => __('Не указано'),
        };
        $formula = match ($terms['formula'] ?? null) {
            ReferralRewardFormula::FixedAmount->value => __('Фиксированная сумма :amount', [
                'amount' => trim((string) ($terms['fixedAmount'] ?? '—').' '.(string) ($terms['fixedCurrency'] ?? '')),
            ]),
            ReferralRewardFormula::PercentageOfSettlement->value => __(':percentage% от оплаты', [
                'percentage' => $terms['percentage'] ?? '—',
            ]),
            default => __('Не указано'),
        };

        return __(':scope: :qualification · :formula', [
            'scope' => $scope,
            'qualification' => $qualification,
            'formula' => $formula,
        ]);
    }

    private static function referralChannelLabel(mixed $channel): string
    {
        return match ($channel) {
            'Telegram' => __('Telegram'),
            'Instagram' => __('Instagram'),
            'YouTube' => __('YouTube'),
            'WhatsApp' => __('WhatsApp'),
            'Website', 'Сайт' => __('Сайт'),
            'Other', 'Другое' => __('Другое'),
            'CRM' => __('CRM'),
            'Link', 'Ссылка' => __('Ссылка'),
            default => is_string($channel) && $channel !== '' ? $channel : __('Другое'),
        };
    }

    private static function referralSourceName(mixed $source): string
    {
        return match ($source) {
            'Назначено в CRM', 'Assigned in CRM' => __('Назначено в CRM'),
            'Персональная ссылка', 'Personal link' => __('Персональная ссылка'),
            default => is_string($source) && $source !== '' ? $source : __('Назначено в CRM'),
        };
    }

    private static function referralRewardTypeLabel(mixed $type): string
    {
        return match ($type) {
            'Начисление', 'Reward earned' => __('Начисление'),
            'Сторно', 'Reward reversed' => __('Сторно'),
            'Ручной бонус', 'Manual reward' => __('Ручной бонус'),
            default => is_string($type) && $type !== '' ? $type : __('Операция'),
        };
    }

    private static function referralPayoutStatusLabel(mixed $status): string
    {
        return match ($status) {
            'Requested', 'Запрошена' => __('Запрошена'),
            'Approved', 'Одобрена' => __('Одобрена'),
            'Paid', 'Отмечена как выплаченная' => __('Отмечена как выплаченная'),
            'Rejected', 'Отклонена' => __('Отклонена'),
            'Cancelled', 'Отменена' => __('Отменена'),
            default => is_string($status) && $status !== '' ? $status : '—',
        };
    }

    /** @return array<string, mixed> */
    private function rewardTermsForm(): array
    {
        $terms = app(GetReferralRewardProgram::class)->handle($this->partnerProfile());

        return [
            'enabled' => $terms['isOverride'],
            'qualification_rule' => $terms['qualificationRule'],
            'formula' => $terms['formula'],
            'fixed_amount' => $terms['fixedAmount'],
            'fixed_currency' => $terms['fixedCurrency'],
            'percentage' => $terms['percentage'],
            'effective_at' => $terms['effectiveAt'] === null
                ? now()->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                : CarbonImmutable::parse($terms['effectiveAt'])->setTimezone(app(OrganizationContext::class)->defaultTimezone()),
            'isOverride' => $terms['isOverride'],
        ];
    }

    /** @return list<Field> */
    private static function rewardTermsSchema(): array
    {
        return [
            Toggle::make('enabled')
                ->label(__('Использовать индивидуальные условия'))
                ->live(),
            Select::make('qualification_rule')
                ->label(__('Начислять'))
                ->options([
                    ReferralRewardQualificationRule::FirstSettledPayment->value => CrmLabel::enum(ReferralRewardQualificationRule::FirstSettledPayment),
                    ReferralRewardQualificationRule::EverySettledPayment->value => CrmLabel::enum(ReferralRewardQualificationRule::EverySettledPayment),
                ])
                ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                ->required(fn (Get $get): bool => (bool) $get('enabled')),
            Select::make('formula')
                ->label(__('Размер бонуса'))
                ->options([
                    ReferralRewardFormula::FixedAmount->value => CrmLabel::enum(ReferralRewardFormula::FixedAmount),
                    ReferralRewardFormula::PercentageOfSettlement->value => CrmLabel::enum(ReferralRewardFormula::PercentageOfSettlement),
                ])
                ->live()
                ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                ->required(fn (Get $get): bool => (bool) $get('enabled')),
            TextInput::make('fixed_amount')
                ->label(__('Фиксированная сумма'))
                ->inputMode('decimal')
                ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value)
                ->required(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value),
            Select::make('fixed_currency')
                ->label(__('Валюта фиксированной суммы'))
                ->options(fn (): array => app(CurrencyCatalog::class)->options())
                ->native(false)
                ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value)
                ->required(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::FixedAmount->value),
            TextInput::make('percentage')
                ->label(__('Процент от оплаты'))
                ->suffix('%')
                ->inputMode('decimal')
                ->visible(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::PercentageOfSettlement->value)
                ->required(fn (Get $get): bool => (bool) $get('enabled') && $get('formula') === ReferralRewardFormula::PercentageOfSettlement->value),
            DateTimePicker::make('effective_at')
                ->label(__('Дата начала действия'))
                ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                ->seconds(false)
                ->required()
                ->visible(fn (Get $get): bool => (bool) $get('enabled')),
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function formatMoneyList(array $items): string
    {
        $values = [];
        foreach ($items as $item) {
            $values[] = $this->formatMoney((string) $item['amountMinor'], (string) $item['currency']);
        }

        return $values === [] ? '—' : implode(' · ', $values);
    }

    private function formatMoney(string $amountMinor, string $currency): string
    {
        return Money::ofMinor($amountMinor, CurrencyCode::from($currency))->toDecimalString().' '.$currency;
    }
}
