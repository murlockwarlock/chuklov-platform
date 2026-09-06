<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralRewardFormula;
use App\Modules\Referrals\Domain\Enums\ReferralRewardQualificationRule;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgram;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use Carbon\CarbonImmutable;

final class GetReferralRewardProgram
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return array<string, mixed> */
    public function handle(?ReferralPartnerProfile $partnerProfile = null): array
    {
        $program = ReferralRewardProgram::query()
            ->where('organization_id', $this->context->id())
            ->first();
        $defaultVersion = $program?->currentVersion()->whereNull('partner_profile_id')->first();
        $overrideVersion = $partnerProfile === null || $program === null
            ? null
            : $this->partnerVersion($program->getKey(), $partnerProfile);
        $isOverride = $overrideVersion instanceof ReferralRewardProgramVersion && $overrideVersion->enabled;
        $version = $isOverride ? $overrideVersion : $defaultVersion;
        $effective = $this->serializeVersion($version, $isOverride ? 'Индивидуальные условия' : 'Общие условия');
        $default = $this->serializeVersion($defaultVersion, 'Общие условия');
        $override = $isOverride ? $this->serializeVersion($overrideVersion, 'Индивидуальные условия') : null;

        return [
            ...$effective,
            'isOverride' => $isOverride,
            'sourceLabel' => $isOverride ? 'Индивидуальные условия' : 'Общие условия',
            'defaultTerms' => $default,
            'overrideTerms' => $override,
            'effectiveTerms' => $effective,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeVersion(?ReferralRewardProgramVersion $version, string $sourceLabel): array
    {
        if (! $version instanceof ReferralRewardProgramVersion) {
            return [
                'enabled' => false,
                'qualificationRule' => null,
                'formula' => null,
                'fixedAmount' => null,
                'fixedCurrency' => null,
                'percentage' => null,
                'effectiveAt' => null,
                'version' => null,
                'sourceLabel' => $sourceLabel,
                'summary' => $sourceLabel.': начисление отключено',
            ];
        }

        $fixedCurrency = CurrencyCode::tryFrom((string) $version->getRawOriginal('fixed_currency'));
        $effectiveAt = $version->getRawOriginal('effective_at');
        $qualificationRule = $version->getRawOriginal('qualification_rule');
        $formula = $version->getRawOriginal('formula');
        $fixedAmount = $version->fixed_amount_minor === null || $fixedCurrency === null
            ? null
            : Money::ofMinor($version->fixed_amount_minor, $fixedCurrency)->toDecimalString();
        $percentage = $version->percentage_basis_points === null
            ? null
            : intdiv($version->percentage_basis_points, 100).'.'.str_pad((string) ($version->percentage_basis_points % 100), 2, '0', STR_PAD_LEFT);
        $qualificationLabel = $qualificationRule === ReferralRewardQualificationRule::FirstSettledPayment->value
            ? 'первая оплата'
            : 'каждая оплата';
        $formulaLabel = $formula === ReferralRewardFormula::FixedAmount->value
            ? ($fixedAmount ?? '—').' '.($fixedCurrency?->value ?? '')
            : ($percentage ?? '—').'% от оплаты';
        $summary = (bool) $version->enabled
            ? $sourceLabel.': '.$qualificationLabel.' · '.$formulaLabel
            : $sourceLabel.': начисление отключено';

        return [
            'enabled' => (bool) $version->enabled,
            'qualificationRule' => $qualificationRule,
            'formula' => $formula,
            'fixedAmount' => $fixedAmount,
            'fixedCurrency' => $fixedCurrency?->value,
            'percentage' => $percentage,
            'effectiveAt' => $effectiveAt === null ? null : CarbonImmutable::parse((string) $effectiveAt)->toIso8601String(),
            'version' => (int) $version->version,
            'sourceLabel' => $sourceLabel,
            'summary' => $summary,
        ];
    }

    private function partnerVersion(int $programId, ReferralPartnerProfile $partnerProfile): ?ReferralRewardProgramVersion
    {
        return ReferralRewardProgramVersion::query()
            ->where('organization_id', $this->context->id())
            ->where('program_id', $programId)
            ->where('partner_profile_id', $partnerProfile->getKey())
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->latest('version')
            ->first();
    }
}
