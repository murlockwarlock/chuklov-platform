<?php

namespace App\Modules\Organizations\Domain\Models;

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

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
