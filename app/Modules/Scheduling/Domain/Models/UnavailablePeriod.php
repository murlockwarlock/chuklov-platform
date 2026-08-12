<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Factories\UnavailablePeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read Specialist $specialist
 * @property-read User|null $createdBy
 */
#[Fillable(['starts_at', 'ends_at', 'reason'])]
class UnavailablePeriod extends Model
{
    /** @use HasFactory<UnavailablePeriodFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Specialist, $this> */
    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function instantInterval(): InstantInterval
    {
        return InstantInterval::from(
            CarbonImmutable::parse((string) $this->getAttribute('starts_at')),
            CarbonImmutable::parse((string) $this->getAttribute('ends_at')),
        );
    }

    protected static function newFactory(): UnavailablePeriodFactory
    {
        return UnavailablePeriodFactory::new();
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
