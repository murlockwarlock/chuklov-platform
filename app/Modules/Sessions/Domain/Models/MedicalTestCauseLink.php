<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'medical_test_definition_id', 'medical_cause_definition_id', 'scope_key', 'is_active'])]
class MedicalTestCauseLink extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<MedicalTestDefinition, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(MedicalTestDefinition::class, 'medical_test_definition_id');
    }

    /** @return BelongsTo<MedicalCauseDefinition, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(MedicalCauseDefinition::class, 'medical_cause_definition_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
