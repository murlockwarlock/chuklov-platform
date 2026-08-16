<?php

namespace App\Modules\Surveys\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int $previous_attempt_id
 * @property int $current_attempt_id
 * @property string $status
 * @property array<string, mixed> $comparison_snapshot
 * @property int|null $scenario_event_id
 */
class SurveyComparison extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['comparison_snapshot' => 'encrypted:array'];
    }
}
