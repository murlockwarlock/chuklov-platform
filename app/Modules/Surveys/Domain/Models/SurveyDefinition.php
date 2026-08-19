<?php

namespace App\Modules\Surveys\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $definition_key
 * @property string $title
 * @property string|null $title_en
 * @property string|null $description
 * @property string|null $description_en
 * @property int|null $active_version_id
 * @property bool $is_available
 * @property-read SurveyVersion|null $activeVersion
 */
class SurveyDefinition extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (SurveyDefinition $definition): void {
            if ($definition->isDirty('definition_key')) {
                throw new LogicException('Survey definition keys are immutable.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<SurveyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SurveyVersion::class);
    }

    /** @return BelongsTo<SurveyVersion, $this> */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'active_version_id');
    }

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }
}
