<?php

namespace App\Modules\Commerce\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateMovement;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class ListClientGiftCertificates
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly GiftCertificateBalanceProjection $balances,
    ) {}

    /** @return list<array<string, mixed>> */
    public function handle(?string $locale = null): array
    {
        $client = $this->clientContext->client();

        $certificates = GiftCertificate::query()
            ->where('organization_id', $client->organization_id)
            ->where('current_holder_client_id', $client->getKey())
            ->with([
                'purchaser:id,organization_id,full_name',
                'movements' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id'),
            ])
            ->orderByDesc('issued_at')
            ->get();
        $result = [];

        foreach ($certificates as $certificate) {
            try {
                $balance = $this->balances->balance($certificate);
            } catch (\Throwable) {
                Log::warning('Gift certificate balance was unavailable for portal presentation.', [
                    'organization_id' => (int) $client->organization_id,
                    'client_id' => (int) $client->getKey(),
                    'certificate_id' => (int) $certificate->getKey(),
                    'reason_code' => 'invalid_gift_certificate_history',
                ]);

                continue;
            }

            $issuedAt = $certificate->issued_at;
            $result[] = [
                'id' => (int) $certificate->getKey(),
                'originalAmountMinor' => (int) $certificate->original_amount_minor,
                'balanceMinor' => $balance->minorUnits(),
                'currency' => $certificate->currency->value,
                'status' => $balance->isPositive() ? 'available' : 'spent',
                'statusLabel' => $this->statusLabel($balance->isPositive(), $locale),
                'purchaserName' => $certificate->purchaser?->full_name,
                'issuedAt' => $issuedAt instanceof \DateTimeInterface
                    ? CarbonImmutable::instance($issuedAt)->setTimezone($client->timezone)->format('d.m.Y H:i')
                    : '—',
                'transferUrl' => route('portal.gift-certificates.transfer', $certificate->getKey()),
                'history' => $certificate->movements
                    ->map(fn (GiftCertificateMovement $movement): array => [
                        'type' => $movement->movement_type->value,
                        'label' => $this->movementLabel($movement->movement_type, $locale),
                        'amountMinor' => (int) $movement->amount_minor,
                        'currency' => $this->currency($movement->currency),
                        'occurredAt' => CarbonImmutable::instance($movement->occurred_at)
                            ->setTimezone($client->timezone)
                            ->format('d.m.Y H:i'),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $result;
    }

    private function statusLabel(bool $available, ?string $locale): string
    {
        return $available
            ? ($locale === 'en' ? 'Available' : 'Доступен')
            : ($locale === 'en' ? 'Fully used' : 'Использован');
    }

    private function movementLabel(mixed $type, ?string $locale): string
    {
        $type = $type instanceof GiftCertificateMovementType ? $type : GiftCertificateMovementType::tryFrom((string) $type);

        if ($locale === 'en') {
            return match ($type) {
                GiftCertificateMovementType::Issued => 'Issued',
                GiftCertificateMovementType::Transferred => 'Gift link created',
                GiftCertificateMovementType::Claimed => 'Received',
                GiftCertificateMovementType::Redeemed => 'Applied to payment',
                GiftCertificateMovementType::RedemptionReversed => 'Payment correction',
                default => 'Certificate movement',
            };
        }

        return match ($type) {
            GiftCertificateMovementType::Issued => 'Выпущен',
            GiftCertificateMovementType::Transferred => 'Создана ссылка подарка',
            GiftCertificateMovementType::Claimed => 'Получен',
            GiftCertificateMovementType::Redeemed => 'Применён к оплате',
            GiftCertificateMovementType::RedemptionReversed => 'Возвращён после исправления',
            default => 'Операция сертификата',
        };
    }

    private function currency(mixed $currency): ?string
    {
        return $currency instanceof CurrencyCode ? $currency->value : (is_string($currency) ? $currency : null);
    }
}
