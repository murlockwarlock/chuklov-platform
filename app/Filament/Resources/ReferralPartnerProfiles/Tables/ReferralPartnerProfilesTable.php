<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Tables;

use App\Filament\Resources\ReferralPartnerProfiles\Pages\ListReferralPartnerProfiles;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ReferralPartnerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll(fn (HasTable $livewire): ?string => $livewire instanceof ListReferralPartnerProfiles
                && $livewire->shouldPollMetrics() ? '5s' : null)
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')
                    ->label('Партнёр')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (ReferralPartnerStatus|string $state): string => self::statusLabel($state))
                    ->badge(),
                TextColumn::make('visits_count')->label('Переходы')->numeric()->sortable(),
                TextColumn::make('registrations_count')->label('Регистрации')->numeric()->sortable(),
                TextColumn::make('paid_clients_count')->label('Оплатили')->numeric()->sortable(),
                TextColumn::make('available_summary')
                    ->label('Доступно к выплате')
                    ->state(fn (ReferralPartnerProfile $record): string => self::balance($record, 'available'))
                    ->wrap()
                    ->visibleFrom('sm'),
                TextColumn::make('pending_summary')
                    ->label('Ожидает выплаты')
                    ->state(fn (ReferralPartnerProfile $record): string => self::balance($record, 'pending'))
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('paid_summary')
                    ->label('Выплачено')
                    ->state(fn (ReferralPartnerProfile $record): string => self::balance($record, 'paid'))
                    ->wrap()
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        ReferralPartnerStatus::Active->value => ReferralPartnerStatus::Active->label(),
                        ReferralPartnerStatus::Inactive->value => ReferralPartnerStatus::Inactive->label(),
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Открыть'),
            ])
            ->defaultSort('activated_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    private static function statusLabel(ReferralPartnerStatus|string $state): string
    {
        $status = $state instanceof ReferralPartnerStatus ? $state : ReferralPartnerStatus::tryFrom($state);

        return $status?->label() ?? 'Неизвестно';
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
