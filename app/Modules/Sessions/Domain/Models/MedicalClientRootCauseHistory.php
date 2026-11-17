<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'client_id', 'medical_client_root_cause_id', 'medical_session_id', 'status', 'region', 'region_key_version', 'free_text', 'free_text_key_version', 'relations', 'relations_key_version', 'changed_by'])]
class MedicalClientRootCauseHistory extends Model
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

    /** @return BelongsTo<MedicalClientRootCause, $this> */
    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(MedicalClientRootCause::class, 'medical_client_root_cause_id');
    }

    /** @return BelongsTo<MedicalSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(MedicalSession::class, 'medical_session_id');
    }

    protected function casts(): array
    {
        return [
            'region_key_version' => 'integer',
            'free_text_key_version' => 'integer',
            'relations_key_version' => 'integer',
        ];
    }
}
