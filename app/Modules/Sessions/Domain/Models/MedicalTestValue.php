<?php

namespace App\Modules\Sessions\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'medical_test_definition_id', 'key', 'label', 'is_abnormal', 'is_active', 'sort_order'])]
class MedicalTestValue extends Model
{
    /** @return BelongsTo<MedicalTestDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MedicalTestDefinition::class, 'medical_test_definition_id');
    }

    protected function casts(): array
    {
        return ['is_abnormal' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
