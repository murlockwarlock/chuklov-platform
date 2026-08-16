<?php

namespace App\Modules\Surveys\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int $survey_definition_id
 * @property int $survey_version_id
 * @property SurveyAttemptStatus $status
 * @property array<string, mixed> $definition_snapshot
 * @property array<string, mixed>|null $answers_snapshot
 * @property array<string, mixed> $scoring_snapshot
 * @property array<string, mixed>|null $result_snapshot
 * @property string|null $metric_schema_key
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property-read Client $client
 * @property-read SurveyDefinition $surveyDefinition
 * @property-read SurveyVersion $surveyVersion
 * @property-read SurveyReport|null $report
 */
class SurveyAttempt extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<SurveyDefinition, $this> */
    public function surveyDefinition(): BelongsTo
    {
        return $this->belongsTo(SurveyDefinition::class);
    }

    /** @return BelongsTo<SurveyVersion, $this> */
    public function surveyVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class);
    }

    /** @return HasOne<SurveyReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(SurveyReport::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SurveyAttemptStatus::class,
            'definition_snapshot' => 'encrypted:array',
            'answers_snapshot' => 'encrypted:array',
            'scoring_snapshot' => 'encrypted:array',
            'result_snapshot' => 'encrypted:array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
