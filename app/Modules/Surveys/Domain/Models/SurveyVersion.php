<?php

namespace App\Modules\Surveys\Domain\Models;

use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $survey_definition_id
 * @property int $version
 * @property SurveyVersionStatus $status
 * @property string $title
 * @property string|null $title_en
 * @property string|null $description
 * @property string|null $description_en
 * @property array<string, mixed> $definition
 * @property array<string, mixed> $scoring
 * @property string|null $metric_schema_key
 * @property string|null $source_reference
 * @property-read SurveyDefinition $surveyDefinition
 */
class SurveyVersion extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SurveyDefinition, $this> */
    public function surveyDefinition(): BelongsTo
    {
        return $this->belongsTo(SurveyDefinition::class);
    }

    protected static function booted(): void
    {
        static::updating(function (SurveyVersion $version): void {
            $originalStatus = SurveyVersionStatus::tryFrom((string) $version->getRawOriginal('status'));
            if ($originalStatus !== SurveyVersionStatus::Draft && $version->isDirty(['title', 'title_en', 'description', 'description_en', 'definition', 'scoring', 'metric_schema_key', 'source_reference'])) {
                throw new LogicException('Published survey versions are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SurveyVersionStatus::class,
            'definition' => 'array',
            'scoring' => 'array',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
