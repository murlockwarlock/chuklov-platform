<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int|null $measured_by_user_id
 * @property string $payload
 * @property int $encryption_key_version
 * @property CarbonImmutable $measured_at
 */
#[Fillable(['organization_id', 'client_id', 'measured_by_user_id', 'payload', 'encryption_key_version', 'measured_at'])]
class MedicalAnthropometricMeasurement extends Model
{
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

    /** @return BelongsTo<User, $this> */
    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
            'measured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
