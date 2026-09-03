<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\WorkingLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'address',
    'timezone',
    'latitude',
    'longitude',
    'map_url',
    'is_active',
    'is_default_office',
])]
class WorkingLocation extends Model
{
    /** @use HasFactory<WorkingLocationFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    protected static function newFactory(): WorkingLocationFactory
    {
        return WorkingLocationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'is_default_office' => 'boolean',
        ];
    }
}
