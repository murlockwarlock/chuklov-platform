<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $version
 * @property bool $enabled
 * @property int|null $fixed_amount_minor
 * @property CurrencyCode|null $fixed_currency
 * @property int|null $percentage_basis_points
 * @property FinancialRoundingMode|null $rounding_mode
 * @property Carbon $effective_at
 */
#[Fillable([])]
class ReferralRewardProgramVersion extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Referral reward program versions are immutable.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Referral reward program versions are immutable.');
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ReferralRewardProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(ReferralRewardProgram::class, 'program_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fixed_amount_minor' => 'integer',
            'percentage_basis_points' => 'integer',
            'fixed_currency' => CurrencyCode::class,
            'rounding_mode' => FinancialRoundingMode::class,
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
