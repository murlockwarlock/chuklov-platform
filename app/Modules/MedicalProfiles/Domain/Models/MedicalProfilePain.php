<?php

namespace App\Modules\MedicalProfiles\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'client_id',
    'medical_profile_id',
    'patient_profile_id',
    'lineage_key',
    'name',
    'frequency_mode',
    'frequency_count',
    'frequency_period_unit',
    'time_of_day',
    'score',
    'sort_order',
    'encryption_key_version',
])]
class MedicalProfilePain extends Model
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

    /** @return BelongsTo<MedicalProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MedicalProfile::class, 'medical_profile_id');
    }

    protected function casts(): array
    {
        return [
            'time_of_day' => 'array',
            'frequency_count' => 'integer',
            'score' => 'integer',
            'sort_order' => 'integer',
            'encryption_key_version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
