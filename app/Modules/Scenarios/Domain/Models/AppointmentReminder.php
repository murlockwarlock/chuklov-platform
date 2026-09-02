<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $offset_value
 * @property ScenarioDelayUnit $offset_unit
 * @property bool $is_enabled
 */
#[Fillable(['recipient_type', 'offset_value', 'offset_unit', 'is_enabled'])]
class AppointmentReminder extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ScenarioAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ScenarioAction::class);
    }

    protected function casts(): array
    {
        return [
            'offset_value' => 'integer',
            'offset_unit' => ScenarioDelayUnit::class,
            'is_enabled' => 'boolean',
        ];
    }
}
