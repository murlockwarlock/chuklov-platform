<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $ai_run_id
 * @property int $encryption_key_version
 * @property string|null $encrypted_system_prompt
 * @property string|null $encrypted_user_prompt
 * @property string|null $encrypted_output_text
 * @property string|null $encrypted_output_payload
 * @property string|null $encrypted_human_review_notes
 * @property string|null $encrypted_human_edited_output
 * @property-read Organization $organization
 * @property-read AiRun $run
 */
#[Fillable([
    'organization_id',
    'ai_run_id',
    'encryption_key_version',
    'encrypted_system_prompt',
    'encrypted_user_prompt',
    'encrypted_output_text',
    'encrypted_output_payload',
    'encrypted_human_review_notes',
    'encrypted_human_edited_output',
])]
class AiRunPayload extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
        ];
    }
}
