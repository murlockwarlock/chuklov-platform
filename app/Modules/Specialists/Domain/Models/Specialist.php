<?php

namespace App\Modules\Specialists\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use Database\Factories\SpecialistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Organization $organization
 * @property-read User|null $staffUser
 */
#[Fillable(['display_name', 'timezone'])]
class Specialist extends Model
{
    /** @use HasFactory<SpecialistFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /** @return HasMany<SpecialistWorkingHour, $this> */
    public function workingHours(): HasMany
    {
        return $this->hasMany(SpecialistWorkingHour::class);
    }

    /** @return HasMany<ScheduleException, $this> */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    /** @return HasMany<UnavailablePeriod, $this> */
    public function unavailablePeriods(): HasMany
    {
        return $this->hasMany(UnavailablePeriod::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<SpecialistServiceAssignment, $this> */
    public function specialistServiceAssignments(): HasMany
    {
        return $this->hasMany(SpecialistServiceAssignment::class);
    }

    protected static function newFactory(): SpecialistFactory
    {
        return SpecialistFactory::new();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
