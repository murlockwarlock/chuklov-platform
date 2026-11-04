<?php

namespace App\Modules\Feedback\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([])]
class FeedbackSubmission extends Model
{
    protected $hidden = ['internal_feedback'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsToMany<FeedbackTheme, $this> */
    public function themes(): BelongsToMany
    {
        return $this->belongsToMany(
            FeedbackTheme::class,
            'feedback_submission_themes',
            'feedback_submission_id',
            'feedback_theme_id',
        )->withPivot(['organization_id', 'client_id']);
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'internal_feedback' => 'encrypted',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
