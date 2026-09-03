<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use Carbon\CarbonImmutable;
use Database\Factories\LocationDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'area_name',
    'weekday',
    'specific_date',
    'start_time',
    'end_time',
    'timezone',
    'is_active',
    'notes',
])]
class LocationDay extends Model
{
    /** @use HasFactory<LocationDayFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function appliesTo(LocalDate $date): bool
    {
        return ($this->specific_date !== null && CarbonImmutable::parse((string) $this->specific_date)->toDateString() === $date->value)
            || ($this->weekday !== null && (int) $this->weekday === $date->weekday());
    }

    public function wallClockInterval(): WallClockInterval
    {
        return WallClockInterval::from($this->start_time, $this->end_time);
    }

    protected static function newFactory(): LocationDayFactory
    {
        return LocationDayFactory::new();
    }

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'specific_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }
}
