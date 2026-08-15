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
 * @property string|null $anamnesis
 * @property string|null $complaints_goals
 * @property string|null $operations_injuries
 * @property string|null $medicines
 * @property string|null $supplements
 * @property int $encryption_key_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Client $client
 */
#[Fillable(['organization_id', 'client_id', 'anamnesis', 'complaints_goals', 'operations_injuries', 'medicines', 'supplements', 'encryption_key_version'])]
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

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
