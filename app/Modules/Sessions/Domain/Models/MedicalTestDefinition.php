<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'key', 'name', 'section', 'is_active', 'sort_order'])]
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

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
