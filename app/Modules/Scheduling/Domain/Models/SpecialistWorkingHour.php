<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Scheduling\Domain\ValueObjects\WorkingHourInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Database\Factories\SpecialistWorkingHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read Specialist $specialist
 */
#[Fillable(['weekday', 'start_time', 'end_time', 'starts_on', 'ends_on', 'is_active'])]
class SpecialistWorkingHour extends Model
{
    /** @use HasFactory<SpecialistWorkingHourFactory> */
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

    public function wallClockInterval(): WallClockInterval
    {
        return WallClockInterval::from($this->start_time, $this->end_time);
    }

    public function appliesOn(LocalDate|string $date): bool
    {
        return WorkingHourInterval::from([
            'weekday' => $this->weekday,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'starts_on' => $this->starts_on,
            'ends_on' => $this->ends_on,
        ])->appliesOn($date);
    }

    protected static function newFactory(): SpecialistWorkingHourFactory
    {
        return SpecialistWorkingHourFactory::new();
    }

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
