<?php

namespace App\Modules\Referrals\Application;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

final class NotifyReferralPayoutRequest
{
    public function handle(ReferralPayoutRequest $request): void
    {
        $organization = $request->organization;
        if (! $organization instanceof Organization) {
            return;
        }

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('organization_id', $organization->getKey())
                ->where('is_active', true))
            ->get();
        $recipients = $users->filter(
            fn (User $user): bool => $user->hasPermission(OrganizationPermission::ViewFinance, $organization),
        );

        if ($recipients->isEmpty()) {
            return;
        }

        $partnerName = trim((string) $request->beneficiary?->full_name) ?: 'Партнёр';
        $currency = CurrencyCode::tryFrom((string) $request->getRawOriginal('currency'));
        $amount = $currency === null
            ? '—'
            : Money::ofMinor((int) $request->amount_minor, $currency)->toDecimalString().' '.$currency->value;

        Notification::make()
            ->title('Новая заявка на выплату')
            ->body($partnerName.' · '.$amount)
            ->actions([
                Action::make('openPayoutRequest')
                    ->label('Открыть заявку')
                    ->url(ReferralPayoutRequestResource::getUrl('view', ['record' => $request]))
                    ->button()
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients->values()->all());
    }
}
