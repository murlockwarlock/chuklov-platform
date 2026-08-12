<?php

namespace App\Modules\Services\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Organization $organization
 */
#[Fillable([
    'name',
    'summary',
    'catalog_type',
    'name_ru',
    'name_en',
    'description_ru',
    'description_en',
    'category',
    'duration_minutes',
    'buffer_minutes',
    'formats',
    'price_minor',
    'price_currency',
    'payment_policy',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
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

    public function catalogItemType(): CatalogItemType
    {
        $value = $this->getAttribute('catalog_type');

        return $value instanceof CatalogItemType ? $value : CatalogItemType::from((string) $value);
    }

    public function durationMinutes(): ?int
    {
        $value = $this->getAttribute('duration_minutes');

        return $value === null ? null : (int) $value;
    }

    /** @return list<string> */
    public function supportedFormats(): array
    {
        $value = $this->getAttribute('formats');

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $format): bool => is_string($format),
        ));
    }

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'catalog_type' => CatalogItemType::class,
            'formats' => 'array',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'price_minor' => 'integer',
        ];
    }
}
