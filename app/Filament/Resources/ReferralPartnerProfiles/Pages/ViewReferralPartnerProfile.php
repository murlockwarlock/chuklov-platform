<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ReferralPartnerProfiles\ReferralPartnerProfileResource;
use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralPartner;
use App\Modules\Referrals\Application\GetReferralPartnerWorkspace;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewReferralPartnerProfile extends ViewRecord
{
    protected static string $resource = ReferralPartnerProfileResource::class;

    protected static ?string $title = 'Партнёрский кабинет';

    /** @var array<string, mixed>|null */
    private ?array $workspace = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openClient')
                ->label('Открыть клиента')
                ->icon('heroicon-o-user')
                ->url(fn (): string => ClientResource::getUrl('view', ['record' => $this->partnerProfile()->client_id]))
                ->visible(fn (): bool => $this->canViewClient()),
            Action::make('createCampaignLink')
                ->label('Создать ссылку')
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')
                        ->label('Название')
                        ->placeholder('Instagram — шапка профиля')
                        ->required()
                        ->maxLength(180),
                    Select::make('channel')
                        ->label('Канал')
                        ->options(ReferralCampaignChannel::options())
                        ->native(false)
                        ->required(),
                ])
                ->visible(fn (): bool => $this->partnerProfile()->isActive() && $this->canManageClients())
                ->action(function (array $data): void {
                    app(CreateReferralCampaignLink::class)->handle(
                        client: $this->partnerClient(),
                        name: (string) $data['name'],
                        channel: ReferralCampaignChannel::from((string) $data['channel']),
                        actor: $this->actor(),
                    );
                    $this->workspace = null;
                    Notification::make()->title('Реферальная ссылка создана')->success()->send();
                }),
            Action::make('disableCampaignLink')
                ->label('Отключить ссылку')
                ->icon('heroicon-o-link-slash')
                ->color('gray')
                ->schema([
                    Select::make('campaign_link_id')
                        ->label('Ссылка')
                        ->options(fn (): array => $this->partnerProfile()
                            ->activeCampaignLinks()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($link): array => [$link->getKey() => $link->name.' · '.(ReferralCampaignChannel::tryFrom((string) $link->getRawOriginal('channel'))?->label() ?? 'Другое')])
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
                    Notification::make()->title('Реферальная ссылка отключена')->success()->send();
                }),
            Action::make('activatePartner')
                ->label('Активировать партнёра')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->visible(fn (): bool => ! $this->partnerProfile()->isActive() && $this->canManageClients())
                ->action(function (): void {
                    app(ActivateReferralPartner::class)->handle($this->partnerClient(), 'crm', $this->actor());
                    $this->workspace = null;
                    Notification::make()->title('Партнёрская программа активирована')->success()->send();
                }),
            Action::make('deactivatePartner')
                ->label('Отключить партнёрскую программу')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->partnerProfile()->isActive() && $this->canManageClients())
                ->action(function (): void {
                    app(DeactivateReferralPartner::class)->handle($this->partnerClient(), $this->actor());
                    $this->workspace = null;
                    Notification::make()->title('Партнёрская программа отключена')->success()->send();
                }),
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
                'name' => (string) ($link['name'] ?? 'Ссылка'),
                'channel' => (string) ($link['channel'] ?? 'Другое'),
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
            'Переходы: '.(int) ($stats['visits'] ?? 0),
            'Регистрации: '.(int) ($stats['registrations'] ?? 0),
            'Оплатили: '.(int) ($stats['paidClients'] ?? 0),
            'Доступно: '.($available === [] ? '—' : implode(' · ', $available)),
            'Ожидает выплаты: '.($pending === [] ? '—' : implode(' · ', $pending)),
            'Выплачено: '.($paid === [] ? '—' : implode(' · ', $paid)),
        ]);
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
                    ? 'Дата не указана'
                    : CarbonImmutable::parse((string) $client['registeredAt'])->format('d.m.Y H:i'),
                'origin' => ($client['linkName'] ?? 'Назначено в CRM').' / '.($client['channel'] ?? 'CRM'),
                'paymentStatus' => ($client['paidClient'] ?? false) ? 'Оплатил' : 'Не оплатил',
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
                'type' => (string) ($reward['typeLabel'] ?? 'Операция'),
                'amount' => $this->formatMoney((string) ($reward['amountMinor'] ?? 0), (string) ($reward['currency'] ?? '')),
                'client' => (string) ($reward['clientName'] ?? 'Клиент не указан'),
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
                'status' => (string) ($payout['statusLabel'] ?? '—'),
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
        return app(OrganizationAuthorizer::class)->allows(
            $this->actor(),
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewClients,
        );
    }

    private function canManageClients(): bool
    {
        return app(OrganizationAuthorizer::class)->allows(
            $this->actor(),
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageClients,
        );
    }

    /** @param array<int, array<string, mixed>> $items */
    private function formatMoneyList(array $items): string
    {
        $values = [];
        foreach ($items as $item) {
            $values[] = $this->formatMoney((string) $item['amountMinor'], (string) $item['currency']);
        }

        return implode(' · ', $values);
    }

    private function formatMoney(string $amountMinor, string $currency): string
    {
        return Money::ofMinor($amountMinor, CurrencyCode::from($currency))->toDecimalString().' '.$currency;
    }
}
