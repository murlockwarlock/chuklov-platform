<?php

namespace App\Filament\Resources\Clients\Schemas;

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
                Section::make('Клиент')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->formatStateUsing(fn (mixed $state): string => '#'.$state)
                            ->fontFamily('mono'),
                        TextEntry::make('phone')
                            ->label('Телефон')
                            ->placeholder('Не указан')
                            ->fontFamily('mono')
                            ->wrap(),
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder('Не указан')
                            ->wrap(),
                        TextEntry::make('communication_identities')
                            ->label('Каналы связи')
                            ->state(function (Client $record): string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                $identities = app(GetClientCommunicationIdentities::class)->handle($actor, $record);

                                if ($identities === []) {
                                    return 'Каналы не подключены';
                                }

                                return collect($identities)
                                    ->map(fn (array $item): string => $item['summary'])
                                    ->implode("\n");
                            })
                            ->placeholder('Каналы не подключены')
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('language')
                            ->label('Язык')
                            ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
                        TextEntry::make('timezone')
                            ->label('Часовой пояс')
                            ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state))
                            ->wrap(),
                        TextEntry::make('lead_source')
                            ->label('Источник визита')
                            ->placeholder('Не указан')
                            ->formatStateUsing(fn (mixed $state): string => AttributionSourcePresentation::label(is_string($state) ? $state : null))
                            ->wrap(),
                        TextEntry::make('referral_code')
                            ->label('Код рекомендации')
                            ->fontFamily('mono')
                            ->placeholder('Не указан')
                            ->wrap(),
                        TextEntry::make('attribution_source')
                            ->label('Первая атрибуция')
                            ->state(function (Client $record): string {
                                $attribution = $record->getRelationValue('attribution');

                                return $attribution instanceof ClientAttribution
                                    ? AttributionSourcePresentation::label(
                                        $attribution->source,
                                        $attribution->source_type,
                                    )
                                    : 'Не указан';
                            })
                            ->placeholder('Не указан')
                            ->wrap(),
                        TextEntry::make('marketing_consent_summary')
                            ->label('Маркетинговые сообщения')
                            ->state(function (Client $record): array {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return ['Требуется авторизация'];
                                }

                                $consent = app(GetLatestClientMarketingConsent::class)->handle($actor, $record);

                                if (! $consent instanceof ClientConsent) {
                                    return ['Согласие не зафиксировано'];
                                }

                                $recordedAt = $consent->recorded_at
                                    ->copy()
                                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                                    ->format('d.m.Y H:i');
                                $recordedBy = $consent->getRelationValue('recordedBy');

                                return [
                                    $consent->granted ? 'Согласие есть' : 'Согласие отозвано',
                                    'Зафиксировано: '.$recordedAt,
                                    'Источник: '.self::marketingConsentEvidenceLabel((string) $consent->evidence),
                                    'Версия: '.$consent->version,
                                    'Кем: '.($recordedBy instanceof User ? $recordedBy->name : 'Клиентом через портал'),
                                ];
                            })
                            ->listWithLineBreaks()
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('booking_restriction_status')
                            ->label('Самостоятельная запись')
                            ->state(fn (Client $record): string => $record->activeBookingRestriction === null ? 'Разрешена' : 'Ограничена')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Разрешена' ? 'success' : 'danger')
                            ->helperText(fn (Client $record): ?string => $record->activeBookingRestriction?->reason ? 'Причина: '.$record->activeBookingRestriction->reason : null)
                            ->wrap(),
                        TextEntry::make('balance_summary')
                            ->label('К оплате')
                            ->state(function (Client $record): string {
                                $actor = auth()->user();

                                if (! $actor instanceof User || ! app(FinanceAuthorization::class)->allowsView($actor)) {
                                    return 'Недоступно';
                                }

                                $summary = app(GetClientBalanceSummary::class)->handle($actor, $record);

                                if ($summary === null) {
                                    return 'Расчёт недоступен';
                                }

                                if ($summary === []) {
                                    return 'Открытых начислений нет';
                                }

                                return collect($summary)
                                    ->map(fn (array $item): string => Money::ofMinor($item['outstandingMinor'], $item['currency'])->toDecimalString().' '.$item['currency'])
                                    ->implode(', ');
                            })
                            ->placeholder('Нет данных')
                            ->wrap(),
                        TextEntry::make('finance_link')
                            ->label('Оплаты')
                            ->state('Открыть оплаты')
                            ->url(fn (Client $record): string => app(FinancePresentation::class)->clientFinanceUrl($record))
                            ->visible(fn (): bool => app(FinancePresentation::class)->canViewFinance()),
                    ])
                    ->columns(2)
                    ->extraAttributes(['class' => 'h-fit']),

                Section::make('Клинический профиль')
                    ->description('Защищённые данные (Class C)')
                    ->schema([
                        TextEntry::make('anamnesis')
                            ->label('Клинический анамнез')
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->anamnesis;
                            })
                            ->placeholder('Не заполнен')
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('complaints_goals')
                            ->label('Жалобы, ВАШ и цели')
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->complaintsGoals;
                            })
                            ->placeholder('Не указаны')
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('operations_injuries')
                            ->label('Операции и травмы')
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->operationsInjuries;
                            })
                            ->placeholder('Не указаны')
                            ->wrap(),
                        TextEntry::make('medicines')
                            ->label('Лекарственные препараты')
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->medicines;
                            })
                            ->placeholder('Не указаны')
                            ->wrap(),
                        TextEntry::make('supplements')
                            ->label('Нутрицевтики и БАДы')
                            ->state(function (Client $record): ?string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                return app(GetMedicalProfile::class)->handle($actor, $record)?->supplements;
                            })
                            ->placeholder('Не указаны')
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(2)
                    ->extraAttributes(['class' => 'h-fit']),
            ]);
    }

    private static function marketingConsentEvidenceLabel(string $evidence): string
    {
        return match ($evidence) {
            'crm' => 'Зафиксировано оператором в CRM',
            'telegram' => 'Сообщение клиента в Telegram',
            'phone' => 'Телефонный разговор',
            'written' => 'Письменное согласие',
            'portal' => 'Подтверждено клиентом в портале',
            default => 'Источник не указан',
        };
    }
}
