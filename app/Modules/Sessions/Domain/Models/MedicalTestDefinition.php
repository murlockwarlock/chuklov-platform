<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'key', 'name', 'section', 'test_type', 'catalog_config', 'catalog_set', 'default_in_session', 'is_active', 'sort_order'])]
class MedicalTestDefinition extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<MedicalTestValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(MedicalTestValue::class, 'medical_test_definition_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return HasMany<MedicalTestCauseLink, $this> */
    public function causeLinks(): HasMany
    {
        return $this->hasMany(MedicalTestCauseLink::class, 'medical_test_definition_id');
    }

    public function type(): ?MedicalTestType
    {
        $value = $this->getAttribute('test_type');

        return is_string($value) ? MedicalTestType::tryFrom($value) : null;
    }

    /** @return array<string, mixed> */
    public function catalogConfiguration(): array
    {
        $configuration = $this->getAttribute('catalog_config');

        return is_array($configuration) ? $configuration : [];
    }

    protected function casts(): array
    {
        return [
            'catalog_config' => 'array',
            'default_in_session' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
