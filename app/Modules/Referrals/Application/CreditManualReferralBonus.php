<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreditManualReferralBonus
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyCatalog $catalog,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        ReferralPartnerProfile|int $partner,
        string $amount,
        string $currency,
        string $reason,
        ?string $comment,
        string $idempotencyKey,
    ): ReferralRewardLedgerEntry {
        $organization = $this->authorization->authorizeManage($actor);
        $profileId = $partner instanceof ReferralPartnerProfile ? (int) $partner->getKey() : $partner;
        $currencyCode = $this->currency($currency);
        $money = $this->money($amount, $currencyCode);
        $reason = trim($reason);
        $comment = $comment === null ? null : trim($comment);
        $idempotencyKey = trim($idempotencyKey);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину бонуса до 500 символов.']);
        }

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw ValidationException::withMessages(['comment' => 'Комментарий не должен превышать 1000 символов.']);
        }

        if (preg_match('/^[A-Za-z0-9:_-]{8,191}$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Идентификатор операции имеет недопустимый формат.']);
        }

        $profile = ReferralPartnerProfile::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($profileId)
            ->firstOrFail();
        $beneficiaryId = (int) $profile->client_id;
        $requestHash = hash('sha256', json_encode([
            'partner_profile_id' => $profile->getKey(),
            'amount_minor' => $money->minorUnits(),
            'currency' => $currencyCode->value,
            'reason' => $reason,
            'comment' => $comment,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor,
            $organization,
            $profile,
            $money,
            $currencyCode,
            $reason,
            $comment,
            $idempotencyKey,
            $requestHash,
        ): ReferralRewardLedgerEntry {
            $existing = ReferralRewardLedgerEntry::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ReferralRewardLedgerEntry) {
                if ($existing->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот идентификатор уже использован для другой операции.']);
                }

                return $existing;
            }

            $beneficiary = $profile->client()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $entry = new ReferralRewardLedgerEntry;
            $entry->forceFill([
                'organization_id' => $organization->getKey(),
                'beneficiary_client_id' => $beneficiary->getKey(),
                'referred_client_id' => null,
                'referral_relationship_id' => null,
                'referral_commercial_evidence_id' => null,
                'financial_obligation_id' => null,
                'financial_ledger_entry_id' => null,
                'reward_program_version_id' => null,
                'entry_type' => ReferralRewardLedgerEntryType::ManualCredit->value,
                'amount_minor' => $money->minorUnits(),
                'currency' => $currencyCode->value,
                'reason_type' => 'manual_bonus',
                'reason' => $reason,
                'comment' => $comment,
                'created_by_user_id' => $actor->getKey(),
                'reverses_entry_id' => null,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'occurred_at' => now(),
            ]);
            $entry->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'referral.reward.manual_credit',
                targetType: ReferralRewardLedgerEntry::class,
                targetId: (string) $entry->getKey(),
                metadata: [
                    'beneficiary_client_id' => $beneficiary->getKey(),
                    'partner_profile_id' => $profile->getKey(),
                    'amount_minor' => $money->minorUnits(),
                    'currency' => $currencyCode->value,
                    'reason_present' => true,
                    'comment_present' => $comment !== null,
                ],
            );

            return $entry->refresh();
        });
    }

    private function currency(string $value): CurrencyCode
    {
        try {
            return $this->catalog->code($value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['currency' => 'Выберите допустимую валюту.']);
        }
    }

    private function money(string $amount, CurrencyCode $currency): Money
    {
        try {
            $money = Money::fromDecimal($amount, $currency);
            $money->assertPositive();

            return $money;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => 'Укажите положительную сумму в допустимом формате.']);
        }
    }
}
