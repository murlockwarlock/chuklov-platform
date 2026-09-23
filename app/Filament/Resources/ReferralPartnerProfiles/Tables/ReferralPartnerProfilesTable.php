<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ReferralPartnerProfiles\Pages\ListReferralPartnerProfiles;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ReferralPartnerProfilesTable
{
    public static function configure(Table $table): Table
    {
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->poll(fn (HasTable $livewire): ?string => $livewire instanceof ListReferralPartnerProfiles
                && $livewire->shouldPollMetrics() ? '5s' : null)
            ->stackedOnMobile()
            ->recordActionsPosition(RecordActionsPosition::AfterColumns)
            ->columns([
                TextColumn::make('client.full_name')
                    ->label(__('Партнёр'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (ReferralPartnerProfile $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients))
                    ->color(fn (ReferralPartnerProfile $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary')
                    ->disabledClick(fn (ReferralPartnerProfile $record): bool => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null),
                TextColumn::make('status')
                    ->label(__('Статус'))
                    ->formatStateUsing(fn (ReferralPartnerStatus|string $state): string => self::statusLabel($state))
                    ->badge(),
                TextColumn::make('visits_count')
                    ->label(__('Переходы'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registrations_count')->label(__('Регистрации'))->numeric()->sortable(),
                TextColumn::make('paid_clients_count')
                    ->label(__('Оплатили'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_summary')
                    ->label(__('Доступно к выплате'))
                    ->state(fn (ReferralPartnerProfile $record): string => self::balance($record, 'available'))
                    ->wrap(),
                TextColumn::make('pending_summary')
                    ->label(__('Ожидает выплаты'))
                    ->state(fn (ReferralPartnerProfile $record): string => self::balance($record, 'pending'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_summary')
                    ->label(__('Выплачено'))
                    ->state(fn (ReferralPartnerProfile $record): string => self::balance($record, 'paid'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Статус'))
                    ->options([
                        ReferralPartnerStatus::Active->value => CrmLabel::enum(ReferralPartnerStatus::Active),
                        ReferralPartnerStatus::Inactive->value => CrmLabel::enum(ReferralPartnerStatus::Inactive),
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label(__('Открыть')),
            ])
            ->defaultSort('activated_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    private static function statusLabel(ReferralPartnerStatus|string $state): string
    {
        $status = $state instanceof ReferralPartnerStatus ? $state : ReferralPartnerStatus::tryFrom($state);

        return CrmLabel::enum($status) ?? __('Неизвестно');
    }

    private static function balance(ReferralPartnerProfile $record, string $key): string
    {
        $summary = $record->getAttribute('balance_summary');
        $summary = is_string($summary) ? json_decode($summary, true) : $summary;

        if (! is_array($summary)) {
            return '—';
        }

        $values = [];
        foreach ($summary as $currencyValue => $amounts) {
            $currency = CurrencyCode::tryFrom((string) $currencyValue);
            $amount = is_array($amounts) ? ($amounts[$key] ?? null) : null;

            if ($currency === null || (! is_int($amount) && ! is_float($amount) && ! is_string($amount))) {
                continue;
            }

            try {
                $values[] = Money::ofMinor((string) $amount, $currency)->toDecimalString().' '.$currency->value;
            } catch (\Throwable) {
                continue;
            }
        }

        return $values === [] ? '—' : implode(' · ', $values);
    }
}
