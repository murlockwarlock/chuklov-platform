<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralRewardFormula;
use App\Modules\Referrals\Domain\Enums\ReferralRewardQualificationRule;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgram;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use App\Modules\Security\Application\RecordAuditEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class SaveReferralRewardProgram
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyCatalog $catalog,
        private readonly CurrencyConfigurationService $currencyConfiguration,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        bool $enabled,
        ?string $qualificationRule,
        ?string $formula,
        ?string $fixedAmount,
        ?string $fixedCurrency,
        ?string $percentage,
        string|DateTimeInterface|null $effectiveAt,
    ): ReferralRewardProgramVersion {
        $organization = $this->authorization->authorizeManage($actor);
        $qualification = $enabled ? $this->qualification($qualificationRule) : null;
        $rewardFormula = $enabled ? $this->formula($formula) : null;
        $effective = $this->effectiveAt($effectiveAt);
        $rounding = $enabled ? $this->rounding($organization->getKey()) : null;
        $fixed = null;
        $currency = null;
        $basisPoints = null;

        if ($enabled && $rewardFormula === ReferralRewardFormula::FixedAmount) {
            if (! is_string($fixedAmount) || ! is_string($fixedCurrency)) {
                throw ValidationException::withMessages([
                    'fixed_amount' => 'Укажите фиксированную сумму и валюту.',
                ]);
            }

            try {
                $currency = $this->catalog->code($fixedCurrency);

                $separator = strrpos($fixedAmount, '.');
                $fraction = $separator === false ? '' : substr($fixedAmount, $separator + 1);

                if (strlen($fraction) > $this->catalog->scale($currency)) {
                    throw new InvalidArgumentException('The fixed reward amount has unsupported precision.');
                }

                $fixed = Money::fromDecimal($fixedAmount, $currency);
                $fixed->assertPositive();
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'fixed_amount' => 'Укажите положительную сумму в допустимом формате.',
                    'fixed_currency' => 'Выберите допустимую валюту.',
                ]);
            }
        }

        if ($enabled && $rewardFormula === ReferralRewardFormula::PercentageOfSettlement) {
            $basisPoints = $this->basisPoints($percentage);
        }

        return DB::transaction(function () use (
            $actor,
            $organization,
            $enabled,
            $qualification,
            $rewardFormula,
            $fixed,
            $currency,
            $basisPoints,
            $rounding,
            $effective,
        ): ReferralRewardProgramVersion {
            DB::table('referral_reward_programs')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'current_version_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $program = ReferralRewardProgram::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $versionNumber = ((int) $program->versions()->max('version')) + 1;
            $version = new ReferralRewardProgramVersion;
            $version->forceFill([
                'organization_id' => $organization->getKey(),
                'program_id' => $program->getKey(),
                'version' => $versionNumber,
                'enabled' => $enabled,
                'qualification_rule' => $qualification?->value,
                'formula' => $rewardFormula?->value,
                'fixed_amount_minor' => $fixed?->minorUnits(),
                'fixed_currency' => $currency?->value,
                'percentage_basis_points' => $basisPoints,
                'rounding_mode' => $rounding?->value,
                'effective_at' => $effective,
                'created_by_user_id' => $actor->getKey(),
            ]);
            $version->save();
            $program->forceFill(['current_version_id' => $version->getKey()])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'referral.reward_program.updated',
                targetType: ReferralRewardProgramVersion::class,
                targetId: (string) $version->getKey(),
                metadata: [
                    'enabled' => $enabled,
                    'qualification_rule' => $qualification?->value,
                    'formula' => $rewardFormula?->value,
                    'fixed_amount_minor' => $fixed?->minorUnits(),
                    'fixed_currency' => $currency?->value,
                    'percentage_basis_points' => $basisPoints,
                    'rounding_mode' => $rounding?->value,
                    'version' => $versionNumber,
                    'effective_at' => $effective->toIso8601String(),
                ],
            );

            return $version->refresh();
        });
    }

    private function qualification(?string $value): ReferralRewardQualificationRule
    {
        $rule = is_string($value) ? ReferralRewardQualificationRule::tryFrom($value) : null;

        if ($rule === null) {
            throw ValidationException::withMessages(['qualification_rule' => 'Выберите правило начисления.']);
        }

        return $rule;
    }

    private function formula(?string $value): ReferralRewardFormula
    {
        $formula = is_string($value) ? ReferralRewardFormula::tryFrom($value) : null;

        if ($formula === null) {
            throw ValidationException::withMessages(['formula' => 'Выберите размер бонуса.']);
        }

        return $formula;
    }

    private function rounding(int $organizationId): FinancialRoundingMode
    {
        try {
            return $this->currencyConfiguration->roundingMode($organizationId);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'formula' => 'Сначала настройте валюты и правило округления в разделе «Настройки валют».',
            ]);
        }
    }

    private function effectiveAt(string|DateTimeInterface|null $value): CarbonImmutable
    {
        try {
            $effective = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value ?: now()->toIso8601String(), $this->context->defaultTimezone());
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['effective_at' => 'Укажите корректную дату начала действия.']);
        }

        return $effective->utc();
    }

    private function basisPoints(?string $value): int
    {
        if (! is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/', $value) !== 1) {
            throw ValidationException::withMessages(['percentage' => 'Укажите процент от 0,01 до 100,00.']);
        }

        try {
            $points = BigDecimal::of($value)->multipliedBy(100)->toScale(0, RoundingMode::Unnecessary)->toInt();
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['percentage' => 'Укажите процент от 0,01 до 100,00.']);
        }

        if ($points < 1 || $points > 10000) {
            throw ValidationException::withMessages(['percentage' => 'Укажите процент от 0,01 до 100,00.']);
        }

        return $points;
    }
}
