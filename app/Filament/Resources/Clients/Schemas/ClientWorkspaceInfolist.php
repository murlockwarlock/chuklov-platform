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
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
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

                                if ($identities === []) {
                                    return __('Каналы не подключены');
                                }

                                return collect($identities)
                                    ->map(fn (array $item): string => $item['summary'])
                                    ->implode("\n");
                            })
                            ->placeholder(__('Каналы не подключены'))
                            ->columnSpanFull()
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
                            ->label(__('Первая атрибуция'))
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
                            ->label(__('Пригласил'))
                            ->state(function (Client $record): string {
                                $relationship = $record->getRelationValue('referralRelationship');
                                $referrer = $relationship?->getRelationValue('referrer');
                                $name = trim((string) $referrer?->full_name);

                                return $name !== '' ? $name : ($referrer instanceof Client
                                    ? __('Клиент без имени')
                                    : ($relationship === null ? __('Не указан') : __('Клиент недоступен')));
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
                                    'manual_crm' => __('Указан в CRM'),
                                    'automatic_referral_link' => $relationship->referral_campaign_link_id === null
                                        ? __('Персональная ссылка')
                                        : __('Кампания: :name', [
                                            'name' => ($campaign = $relationship->getRelationValue('referralCampaignLink')) instanceof ReferralCampaignLink
                                                ? ($campaign->name ?: __('не указана'))
                                                : __('не указана'),
                                        ]),
                                    default => __('Источник зафиксирован'),
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
                                ? __('Причина: :reason', ['reason' => $record->activeBookingRestriction->reason])
                                : null)
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
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return __('Требуется авторизация');
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->anamnesis;
                            })
                            ->placeholder(__('Не заполнен'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('complaints_goals')
                            ->label(__('Жалобы, ВАШ и цели'))
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return __('Требуется авторизация');
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->complaintsGoals;
                            })
                            ->placeholder(__('Не указаны'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('operations_injuries')
                            ->label(__('Операции и травмы'))
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return __('Требуется авторизация');
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->operationsInjuries;
                            })
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('medicines')
                            ->label(__('Лекарственные препараты'))
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return __('Требуется авторизация');
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->medicines;
                            })
                            ->placeholder(__('Не указаны'))
                            ->wrap(),
                        TextEntry::make('supplements')
                            ->label(__('Нутрицевтики и БАДы'))
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return __('Требуется авторизация');
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->supplements;
                            })
                            ->placeholder(__('Не указаны'))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(1)
                    ->extraAttributes(['class' => 'h-fit']),
            ]);
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
}
