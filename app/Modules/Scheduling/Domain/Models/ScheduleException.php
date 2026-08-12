<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read Specialist $specialist
 * @property ScheduleExceptionType $exception_type
 */
#[Fillable(['exception_date', 'exception_type', 'start_time', 'end_time', 'reason', 'is_active'])]
class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
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

    public function wallClockInterval(): ?WallClockInterval
    {
        if ($this->exception_type !== ScheduleExceptionType::CustomWindow) {
            return null;
        }

        return WallClockInterval::from($this->start_time, $this->end_time);
    }

    public function dateKey(): string
    {
        return CarbonImmutable::parse((string) $this->getAttribute('exception_date'))->format('Y-m-d');
    }

    protected static function newFactory(): ScheduleExceptionFactory
    {
        return ScheduleExceptionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'exception_date' => 'date:Y-m-d',
            'exception_type' => ScheduleExceptionType::class,
            'is_active' => 'boolean',
        ];
    }
}
