<?php

namespace App\Modules\Surveys\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int $survey_attempt_id
 * @property int $survey_version_id
 * @property string $title
 * @property array<string, mixed> $report_snapshot
 * @property Carbon $materialized_at
 */
class SurveyReport extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SurveyAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SurveyAttempt::class, 'survey_attempt_id');
    }

    protected function casts(): array
    {
        return [
            'report_snapshot' => 'encrypted:array',
            'materialized_at' => 'datetime',
        ];
    }
}
