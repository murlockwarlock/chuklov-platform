<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\FinancePresentation;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Attribution\Application\AttributionSourcePresentation;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\GetClientBalanceSummary;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Application\GetClientCommunicationIdentities;
use App\Modules\Identity\Application\GetLatestClientMarketingConsent;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Domain\Models\TrackerCheckIn;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ClientWorkspaceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->extraAttributes(['class' => 'grid grid-cols-1 lg:grid-cols-2 gap-6 items-start'])
            ->components([
                Section::make(__('Клиент'))
                    ->schema([
                        TextEntry::make('phone')
                            ->label(__('Телефон'))
                            ->placeholder(__('Не указан'))
                            ->fontFamily('mono')
                            ->wrap(),
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder(__('Не указан'))
                            ->wrap(),
                        TextEntry::make('communication_identities')
                            ->label(__('Каналы связи'))
                            ->state(function (Client $record): string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return __('Требуется авторизация');
                                }

                                $identities = app(GetClientCommunicationIdentities::class)->handle($actor, $record);

                                $telegram = collect($identities)->firstWhere('channel', 'telegram');
                                $telegramConnected = $telegram !== null
                                    && $telegram['verificationStatus'] === ChannelIdentityStatus::Verified;
                                $lines = [
                                    e('Telegram: '.($telegramConnected ? __('Подключён') : __('Не подключён'))),
                                ];

                                if ($telegramConnected) {
                                    $username = VerifiedChannelIdentity::normalizeUsername($telegram['externalUsername']);
                                    if ($username !== null && $telegram['telegramUrl'] !== null) {
                                        $lines[] = '<a href="'.e($telegram['telegramUrl']).'" target="_blank" rel="noopener noreferrer" class="crm-entity-link">@'.e($username).'</a>';
                                    }
                                    $externalId = trim($telegram['externalId']);
                                    if ($externalId !== '') {
                                        $lines[] = e('Telegram ID: '.$externalId);
                                    }
                                }

                                foreach ($identities as $item) {
                                    if ($item['channel'] !== 'telegram') {
                                        $lines[] = e($item['summary']);
                                    }
                                }

                                return implode('<br>', array_filter($lines));
                            })
                            ->placeholder(__('Каналы не подключены'))
                            ->columnSpanFull()
                            ->html()
                            ->wrap(),
                        TextEntry::make('language')
                            ->label(__('Язык'))
                            ->formatStateUsing(fn (string $state): string => $state === 'ru' ? __('Русский') : __('Английский')),
                        TextEntry::make('timezone')
                            ->label(__('Часовой пояс'))
                            ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state))
                            ->wrap(),
                        TextEntry::make('lead_source')
                            ->label(__('Источник визита'))
                            ->placeholder(__('Не указан'))
                            ->formatStateUsing(fn (mixed $state): string => AttributionSourcePresentation::label(is_string($state) ? $state : null))
                            ->wrap(),
                        TextEntry::make('referral_code')
                            ->label(__('Код рекомендации'))
                            ->fontFamily('mono')
                            ->placeholder(__('Не указан'))
                            ->wrap(),
                        TextEntry::make('attribution_source')
                            ->label(__('Первый источник'))
                            ->state(function (Client $record): string {
                                $attribution = $record->getRelationValue('attribution');

                                return $attribution instanceof ClientAttribution
                                    ? AttributionSourcePresentation::label(
                                        $attribution->source,
                                        $attribution->source_type,
                                    )
                                    : __('Не указан');
                            })
                            ->placeholder(__('Не указан'))
                            ->wrap(),
                        TextEntry::make('referrer_summary')
                            ->label(__('Кто пригласил'))
                            ->state(function (Client $record): string {
                                $relationship = $record->getRelationValue('referralRelationship');
                                $referrer = $relationship?->getRelationValue('referrer');
                                $name = trim((string) $referrer?->full_name);

                                return $name !== '' ? $name : ($referrer instanceof Client
                                    ? __('Клиент без имени')
                                    : ($relationship === null ? self::text('Не указан') : self::text('Клиент недоступен')));
                            })
                            ->url(function (Client $record): ?string {
                                $relationship = $record->getRelationValue('referralRelationship');
                                $referrer = $relationship?->getRelationValue('referrer');

                                return $referrer instanceof Client ? CrmEntityLinks::clientUrl($referrer) : null;
                            })
                            ->color(function (Client $record): ?string {
                                $relationship = $record->getRelationValue('referralRelationship');
                                $referrer = $relationship?->getRelationValue('referrer');

                                return $referrer instanceof Client && CrmEntityLinks::clientUrl($referrer) !== null ? 'primary' : null;
                            })
                            ->helperText(function (Client $record): ?string {
                                $relationship = $record->getRelationValue('referralRelationship');

                                return $relationship === null ? null : match ($relationship->establishment_method?->value) {
                                    'manual_crm' => self::text('Указан в CRM'),
                                    'automatic_referral_link' => $relationship->referral_campaign_link_id === null
                                        ? self::text('Персональная ссылка')
                                        : self::text('Кампания: :name', [
                                            'name' => ($campaign = $relationship->getRelationValue('referralCampaignLink')) instanceof ReferralCampaignLink
                                                ? ($campaign->name ?: self::text('не указана'))
                                                : self::text('не указана'),
                                        ]),
                                    default => self::text('Источник зафиксирован'),
                                };
                            })
                            ->wrap(),
                        TextEntry::make('marketing_consent_summary')
                            ->label(__('Маркетинговые сообщения'))
                            ->state(function (Client $record): array {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return [__('Требуется авторизация')];
                                }

                                $consent = app(GetLatestClientMarketingConsent::class)->handle($actor, $record);

                                if (! $consent instanceof ClientConsent) {
                                    return [__('Согласие не зафиксировано')];
                                }

                                $recordedAt = $consent->recorded_at
                                    ->copy()
                                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                                    ->format('d.m.Y H:i');
                                $recordedBy = $consent->getRelationValue('recordedBy');

                                return [
                                    $consent->granted ? __('Согласие есть') : __('Согласие отозвано'),
                                    __('Зафиксировано: :date', ['date' => $recordedAt]),
                                    __('Источник: :source', ['source' => self::marketingConsentEvidenceLabel((string) $consent->evidence)]),
                                    __('Версия: :version', ['version' => $consent->version]),
                                    __('Кем: :actor', ['actor' => $recordedBy instanceof User ? $recordedBy->name : __('Клиентом через портал')]),
                                ];
                            })
                            ->listWithLineBreaks()
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('booking_restriction_status')
                            ->label(__('Самостоятельная запись'))
                            ->state(fn (Client $record): string => $record->activeBookingRestriction === null ? 'allowed' : 'restricted')
                            ->formatStateUsing(fn (string $state): string => $state === 'allowed' ? __('Разрешена') : __('Ограничена'))
                            ->badge()
                            ->color(fn (string $state): string => $state === 'allowed' ? 'success' : 'danger')
                            ->helperText(fn (Client $record): ?string => $record->activeBookingRestriction?->reason
                                ? self::text('Причина: :reason', ['reason' => $record->activeBookingRestriction->reason])
                                : null)
                            ->wrap(),
                        TextEntry::make('blacklist_status')
                            ->label(__('Чёрный список'))
                            ->state(fn (Client $record): string => $record->activeBlacklistRestriction === null ? 'not_blacklisted' : 'blacklisted')
                            ->formatStateUsing(fn (string $state): string => $state === 'blacklisted' ? __('В чёрном списке') : __('Не в чёрном списке'))
                            ->badge()
                            ->color(fn (string $state): string => $state === 'blacklisted' ? 'danger' : 'gray')
                            ->helperText(fn (Client $record): ?string => $record->activeBlacklistRestriction === null
                                ? null
                                : (string) __('Причина: :reason. Запись сотрудником CRM доступна.', ['reason' => (string) $record->activeBlacklistRestriction->reason]))
                            ->wrap(),
                        TextEntry::make('balance_summary')
                            ->label(__('К оплате'))
                            ->state(function (Client $record): string {
                                $actor = auth()->user();

                                if (! $actor instanceof User || ! app(FinanceAuthorization::class)->allowsView($actor)) {
                                    return __('Недоступно');
                                }

                                $summary = app(GetClientBalanceSummary::class)->handle($actor, $record);

                                if ($summary === null) {
                                    return __('Расчёт недоступен');
                                }

                                if ($summary === []) {
                                    return __('Открытых начислений нет');
                                }

                                return collect($summary)
                                    ->map(fn (array $item): string => Money::ofMinor($item['outstandingMinor'], $item['currency'])->toDecimalString().' '.$item['currency'])
                                    ->implode(', ');
                            })
                            ->placeholder(__('Нет данных'))
                            ->wrap(),
                        TextEntry::make('finance_link')
                            ->label(__('Оплаты'))
                            ->state(__('Открыть оплаты'))
                            ->url(fn (Client $record): string => app(FinancePresentation::class)->clientFinanceUrl($record))
                            ->visible(fn (): bool => app(FinancePresentation::class)->canViewFinance()),
                        TextEntry::make('tracker_access')
                            ->label(__('Доступ к трекеру'))
                            ->state(fn (Client $record): string => app(ResolveTrackerAccess::class)->handle($record)->statusLabel())
                            ->badge()
                            ->color(fn (Client $record): string => app(ResolveTrackerAccess::class)->handle($record)->allowed() ? 'success' : 'gray'),
                        TextEntry::make('tracker_history')
                            ->label(__('История трекера'))
                            ->state(fn (Client $record): string => TrackerCheckIn::query()
                                ->where('organization_id', $record->organization_id)
                                ->where('client_id', $record->getKey())
                                ->latest('occurred_at')
                                ->limit(10)
                                ->get()
                                ->map(fn (TrackerCheckIn $entry): string => CarbonImmutable::parse((string) $entry->getRawOriginal('occurred_at'))->format('d.m.Y H:i').' — '.$entry->note)
                                ->implode("\n"))
                            ->placeholder(__('Отметок пока нет'))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(2)
                    ->extraAttributes(['class' => 'h-fit']),

                Section::make(__('Клинический профиль'))
                    ->description(__('Защищённые данные'))
                    ->schema([
                        TextEntry::make('anamnesis')
                            ->label(__('Клинический анамнез'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'anamnesis'))
                            ->placeholder(__('Не заполнен'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('complaints')
                            ->label(__('Жалобы'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'complaints'))
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('goals')
                            ->label(__('Цели'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'goals'))
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('operations')
                            ->label(__('Операции'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'operations'))
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('injuries')
                            ->label(__('Травмы'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'injuries'))
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('legacy_complaints_goals')
                            ->label(__('Историческая запись: жалобы и цели'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'complaintsGoals'))
                            ->visible(fn (Client $record): bool => self::medicalProfile($record)?->complaintsGoals !== null)
                            ->placeholder(__('Нет исторической записи'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('legacy_operations_injuries')
                            ->label(__('Историческая запись: операции и травмы'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'operationsInjuries'))
                            ->visible(fn (Client $record): bool => self::medicalProfile($record)?->operationsInjuries !== null)
                            ->placeholder(__('Нет исторической записи'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('medicines')
                            ->label(__('Лекарственные препараты'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'medicines'))
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('supplements')
                            ->label(__('Нутрицевтики и БАДы'))
                            ->state(fn (Client $record): ?string => self::medicalProfileField($record, 'supplements'))
                            ->placeholder(__('Не указаны'))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(1)
                    ->extraAttributes(['class' => 'h-fit']),
            ]);
    }

    /** @param array<string, scalar> $replace */
    private static function text(string $key, array $replace = []): string
    {
        $translated = __($key, $replace);

        return is_string($translated) ? $translated : $key;
    }

    private static function marketingConsentEvidenceLabel(string $evidence): string
    {
        return match ($evidence) {
            'crm' => __('Зафиксировано оператором в CRM'),
            'telegram' => __('Сообщение клиента в Telegram'),
            'phone' => __('Телефонный разговор'),
            'written' => __('Письменное согласие'),
            'portal' => __('Подтверждено клиентом в портале'),
            default => __('Источник не указан'),
        };
    }

    private static function medicalProfile(Client $record): ?MedicalProfileData
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(GetMedicalProfile::class)->handle($actor, $record)
            : null;
    }

    private static function medicalProfileField(Client $record, string $field): ?string
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return __('Требуется авторизация');
        }

        $profile = app(GetMedicalProfile::class)->handle($actor, $record);

        return match ($field) {
            'anamnesis' => $profile?->anamnesis,
            'complaints' => $profile?->complaints,
            'goals' => $profile?->goals,
            'operations' => $profile?->operations,
            'injuries' => $profile?->injuries,
            'complaintsGoals' => $profile?->complaintsGoals,
            'operationsInjuries' => $profile?->operationsInjuries,
            'medicines' => $profile?->medicines,
            'supplements' => $profile?->supplements,
            default => null,
        };
    }
}
