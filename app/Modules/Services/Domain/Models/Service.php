<?php

namespace App\Modules\Services\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
