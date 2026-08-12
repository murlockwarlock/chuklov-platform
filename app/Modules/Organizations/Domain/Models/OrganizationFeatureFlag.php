<?php

namespace App\Modules\Organizations\Domain\Models;

use Database\Factories\OrganizationFeatureFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property bool $enabled
 */
#[Fillable(['feature_key', 'enabled'])]
class OrganizationFeatureFlag extends Model
{
    /** @use HasFactory<OrganizationFeatureFlagFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function newFactory(): OrganizationFeatureFlagFactory
    {
        return OrganizationFeatureFlagFactory::new();
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
