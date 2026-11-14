<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'name', 'group_name', 'synonyms', 'tooltip', 'moderation_status', 'merged_into_id', 'is_active', 'sort_order'])]
class MedicalCauseDefinition extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<MedicalCauseDefinition, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    protected function casts(): array
    {
        return ['synonyms' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer', 'merged_into_id' => 'integer'];
    }
}
