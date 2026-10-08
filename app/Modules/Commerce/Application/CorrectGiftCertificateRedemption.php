<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateMovement;
use App\Modules\Commerce\Domain\Models\GiftCertificateRedemption;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Models\FinanceIdempotencyKey;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorrectGiftCertificateRedemption
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CorrectFinancialPayment $financeCorrection,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly AppendGiftCertificateMovement $movements,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        FinancialLedgerEntry|int $entry,
        string $reason,
        string $idempotencyKey,
    ): FinancialLedgerEntry {
        $organization = $this->authorization->authorizeManage($actor);
        $entryId = $entry instanceof FinancialLedgerEntry ? (int) $entry->getKey() : $entry;
        $reason = trim($reason);
        $key = trim($idempotencyKey);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину исправления не длиннее 500 символов.']);
        }
        if ($key === '' || mb_strlen($key) > 180 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ операции указан неверно.']);
        }

        $redemption = GiftCertificateRedemption::query()
            ->where('organization_id', $organization->getKey())
            ->where('financial_ledger_entry_id', $entryId)
            ->first();
        if (! $redemption instanceof GiftCertificateRedemption) {
            throw (new ModelNotFoundException)->setModel(GiftCertificateRedemption::class, [$entryId]);
        }

        $requestHash = hash('sha256', json_encode([
            'original_id' => $entryId,
            'certificate_id' => $redemption->certificate_id,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor,
            $organization,
            $entryId,
            $redemption,
            $reason,
            $key,
            $requestHash,
        ): FinancialLedgerEntry {
            $certificate = GiftCertificate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($redemption->certificate_id)
                ->lockForUpdate()
                ->firstOrFail();
            $original = FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($entryId)
                ->lockForUpdate()
                ->first();
            if (! $original instanceof FinancialLedgerEntry
                || $original->getRawOriginal('entry_type') !== FinancialLedgerEntryType::GiftCertificateRedemption->value) {
                throw ValidationException::withMessages(['entry' => 'Эта запись не является списанием сертификата.']);
            }

            $lockedRedemption = GiftCertificateRedemption::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($redemption->getKey())
                ->where('financial_ledger_entry_id', $original->getKey())
                ->lockForUpdate()
                ->first();
            if (! $lockedRedemption instanceof GiftCertificateRedemption) {
                throw $this->invalidEntry();
            }

            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($idempotency !== null) {
                $this->assertIdempotency($idempotency, $original, $requestHash);

                return $this->existingResult($idempotency, (int) $organization->getKey());
            }

            $redeemedMovement = GiftCertificateMovement::query()
                ->where('organization_id', $organization->getKey())
                ->where('certificate_id', $certificate->getKey())
                ->where('redemption_id', $lockedRedemption->getKey())
                ->where('movement_type', GiftCertificateMovementType::Redeemed->value)
                ->lockForUpdate()
                ->first();
            if (! $redeemedMovement instanceof GiftCertificateMovement
                || (int) $redeemedMovement->amount_minor !== (int) $lockedRedemption->amount_minor
                || $redeemedMovement->getRawOriginal('currency') !== $lockedRedemption->getRawOriginal('currency')) {
                throw $this->invalidEntry();
            }

            if (FinancialLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('corrects_ledger_entry_id', $original->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['entry' => 'Это списание уже исправлено.']);
            }
            if (GiftCertificateMovement::query()
                ->where('organization_id', $organization->getKey())
                ->where('reverses_movement_id', $redeemedMovement->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['entry' => 'Это списание уже возвращено на сертификат.']);
            }

            DB::table('finance_idempotency_keys')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'idempotency_key' => $key,
                'operation' => 'gift_certificate_correction',
                'subject_type' => FinancialLedgerEntry::class,
                'subject_id' => $original->getKey(),
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idempotency = FinanceIdempotencyKey::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertIdempotency($idempotency, $original, $requestHash);
            if ($idempotency->result_id !== null) {
                return $this->existingResult($idempotency, (int) $organization->getKey());
            }

            $correction = $this->financeCorrection->handle(
                actor: $actor,
                original: $original,
                reason: $reason,
                idempotencyKey: 'gift-certificate-finance-correction:'.$organization->getKey().':'.$key,
            );
            $this->movements->handle(
                certificate: $certificate,
                type: GiftCertificateMovementType::RedemptionReversed,
                amountMinor: (int) $lockedRedemption->amount_minor,
                currency: $lockedRedemption->currency,
                fromHolderClientId: null,
                toHolderClientId: null,
                claimId: null,
                redemptionId: $lockedRedemption->getKey(),
                reversesMovementId: $redeemedMovement->getKey(),
                actorUserId: $actor->getKey(),
                idempotencyKey: 'gift_certificate.redemption_reversed:'.$organization->getKey().':'.$lockedRedemption->getKey(),
                occurredAt: CarbonImmutable::now('UTC'),
            );

            $obligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($original->obligation_id)
                ->firstOrFail();
            $this->reconciliation->handle((int) $organization->getKey(), (int) $obligation->getKey(), true);
            $idempotency->forceFill([
                'result_type' => FinancialLedgerEntry::class,
                'result_id' => $correction->getKey(),
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.gift_certificate.redemption.corrected',
                targetType: FinancialLedgerEntry::class,
                targetId: (string) $correction->getKey(),
                metadata: [
                    'correction_of' => $original->getKey(),
                    'certificate_id' => $certificate->getKey(),
                    'amount_minor' => (int) $lockedRedemption->amount_minor,
                    'currency' => $lockedRedemption->currency->value,
                ],
            );

            return $correction;
        });
    }

    private function assertIdempotency(
        FinanceIdempotencyKey $idempotency,
        FinancialLedgerEntry $original,
        string $requestHash,
    ): void {
        if ($idempotency->operation !== 'gift_certificate_correction'
            || $idempotency->subject_type !== FinancialLedgerEntry::class
            || (int) $idempotency->subject_id !== (int) $original->getKey()
            || $idempotency->request_hash !== $requestHash) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Этот ключ уже использован для другой операции.',
            ]);
        }
    }

    private function existingResult(FinanceIdempotencyKey $idempotency, int $organizationId): FinancialLedgerEntry
    {
        if ($idempotency->result_id === null) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Эта операция ещё обрабатывается.',
            ]);
        }

        return FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->whereKey($idempotency->result_id)
            ->firstOrFail();
    }

    private function invalidEntry(): ValidationException
    {
        return ValidationException::withMessages([
            'entry' => 'История сертификата повреждена или неполна.',
        ]);
    }
}
