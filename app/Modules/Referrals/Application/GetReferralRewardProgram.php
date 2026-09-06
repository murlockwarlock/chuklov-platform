<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgram;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use Carbon\CarbonImmutable;

final class GetReferralRewardProgram
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return array{enabled: bool, qualificationRule: ?string, formula: ?string, fixedAmount: ?string, fixedCurrency: ?string, percentage: ?string, effectiveAt: ?string, version: ?int, isOverride: bool, sourceLabel: string} */
    public function handle(?ReferralPartnerProfile $partnerProfile = null): array
    {
        $program = ReferralRewardProgram::query()
            ->where('organization_id', $this->context->id())
            ->first();
        $version = $program === null
            ? null
            : ($partnerProfile === null
                ? $program->currentVersion()->whereNull('partner_profile_id')->first()
                : $this->partnerVersion($program->getKey(), $partnerProfile));
        $isOverride = $partnerProfile !== null
            && $version instanceof ReferralRewardProgramVersion
            && (int) $version->partner_profile_id === (int) $partnerProfile->getKey()
            && $version->enabled;

        if ($partnerProfile !== null && ! $isOverride && $program !== null) {
            $version = $program->currentVersion()->whereNull('partner_profile_id')->first();
        }

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
                'isOverride' => false,
                'sourceLabel' => 'Общие условия',
            ];
        }

        $fixedCurrency = CurrencyCode::tryFrom((string) $version->getRawOriginal('fixed_currency'));
        $effectiveAt = $version->getRawOriginal('effective_at');

        return [
            'enabled' => (bool) $version->enabled,
            'qualificationRule' => $version->getRawOriginal('qualification_rule'),
            'formula' => $version->getRawOriginal('formula'),
            'fixedAmount' => $version->fixed_amount_minor === null || $fixedCurrency === null
                ? null
                : Money::ofMinor($version->fixed_amount_minor, $fixedCurrency)->toDecimalString(),
            'fixedCurrency' => $fixedCurrency?->value,
            'percentage' => $version->percentage_basis_points === null
                ? null
                : intdiv($version->percentage_basis_points, 100).'.'.str_pad((string) ($version->percentage_basis_points % 100), 2, '0', STR_PAD_LEFT),
            'effectiveAt' => $effectiveAt === null ? null : CarbonImmutable::parse((string) $effectiveAt)->toIso8601String(),
            'version' => (int) $version->version,
            'isOverride' => $isOverride,
            'sourceLabel' => $isOverride ? 'Индивидуальные условия' : 'Общие условия',
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
