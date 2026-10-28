<?php

namespace App\Modules\MedicalProfiles\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int|null $patient_profile_id
 * @property string|null $anamnesis
 * @property string|null $complaints_goals
 * @property string|null $operations_injuries
 * @property string|null $complaints
 * @property string|null $goals
 * @property string|null $operations
 * @property string|null $injuries
 * @property string|null $chronic_conditions
 * @property string|null $clinical_answers
 * @property CarbonImmutable|null $client_confirmed_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $medicines
 * @property string|null $supplements
 * @property int $encryption_key_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Client $client
 * @property-read Collection<int, MedicalProfileHistoryEntry> $historyEntries
 */
#[Fillable(['organization_id', 'client_id', 'anamnesis', 'complaints_goals', 'operations_injuries', 'complaints', 'goals', 'operations', 'injuries', 'medicines', 'supplements', 'encryption_key_version'])]
class MedicalProfile extends Model
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

    /** @return HasMany<MedicalProfilePain, $this> */
    public function pains(): HasMany
    {
        return $this->hasMany(MedicalProfilePain::class, 'medical_profile_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return HasMany<MedicalProfileHistoryEntry, $this> */
    public function historyEntries(): HasMany
    {
        return $this->hasMany(MedicalProfileHistoryEntry::class, 'medical_profile_id')
            ->orderBy('entry_type')
            ->orderBy('year')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
            'client_confirmed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
