<?php

namespace App\Modules\MedicalProfiles\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int $medical_profile_id
 * @property string $lineage_key
 * @property string $entry_type
 * @property int $year
 * @property string $description
 * @property int $encryption_key_version
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['organization_id', 'client_id', 'medical_profile_id', 'lineage_key', 'entry_type', 'year', 'description', 'encryption_key_version', 'sort_order'])]
class MedicalProfileHistoryEntry extends Model
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
            'year' => 'integer',
            'encryption_key_version' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
