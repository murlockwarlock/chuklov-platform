<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'group_name', 'synonyms', 'tooltip', 'moderation_status', 'merged_into_id', 'is_active', 'sort_order'])]
class MedicalCauseDefinition extends Model
{
    public const MODERATION_ACTIVE = 'active';

    public const MODERATION_PENDING = 'moderation';

    public const MODERATION_HIDDEN = 'hidden';

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

    /** @return HasMany<MedicalCauseDefinition, $this> */
    public function mergedSources(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** @return HasMany<MedicalTestCauseLink, $this> */
    public function testLinks(): HasMany
    {
        return $this->hasMany(MedicalTestCauseLink::class, 'medical_cause_definition_id');
    }

    protected function casts(): array
    {
        return ['synonyms' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer', 'merged_into_id' => 'integer'];
    }
}
