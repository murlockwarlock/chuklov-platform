<?php

namespace App\Modules\Commerce\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
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
                'pendingClaim:id,organization_id,certificate_id,status',
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
            $pending = $certificate->pendingClaim;
            $status = $pending instanceof GiftCertificateClaim
                ? 'pending'
                : ($balance->isPositive() ? 'available' : 'spent');
            $result[] = [
                'id' => (int) $certificate->getKey(),
                'originalAmountMinor' => (int) $certificate->original_amount_minor,
                'balanceMinor' => $balance->minorUnits(),
                'currency' => $certificate->currency->value,
                'status' => $status,
                'statusLabel' => $this->statusLabel($status, $locale),
                'purchaserName' => $certificate->purchaser?->full_name,
                'issuedAt' => $issuedAt instanceof \DateTimeInterface
                    ? CarbonImmutable::instance($issuedAt)->setTimezone($client->timezone)->format('d.m.Y H:i')
                    : '—',
                'transferUrl' => route('portal.gift-certificates.transfer', $certificate->getKey()),
                'cancelTransferUrl' => route('portal.gift-certificates.transfer.cancel', $certificate->getKey()),
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

    private function statusLabel(string $status, ?string $locale): string
    {
        return match ($status) {
            'pending' => $locale === 'en' ? 'Waiting for recipient' : 'Ожидает получателя',
            'available' => $locale === 'en' ? 'Available' : 'Доступен',
            default => $locale === 'en' ? 'Fully used' : 'Использован',
        };
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
