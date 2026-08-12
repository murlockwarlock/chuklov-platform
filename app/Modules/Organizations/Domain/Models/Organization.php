<?php

namespace App\Modules\Organizations\Domain\Models;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Models\Booking;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'timezone'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<OrganizationSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(OrganizationSetting::class);
    }

    /** @return HasMany<OrganizationFeatureFlag, $this> */
    public function featureFlags(): HasMany
    {
        return $this->hasMany(OrganizationFeatureFlag::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function defaultTimezone(): string
    {
        $configured = $this->settings()
            ->where('setting_key', OrganizationSettingKey::DefaultTimezone->value)
            ->first();
        $timezone = $configured === null
            ? $this->timezone
            : ($configured->string_value ?? $this->timezone);

        return IanaTimezone::from($timezone)->value;
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
