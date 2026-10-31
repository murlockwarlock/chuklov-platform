<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'client_id', 'medical_session_id', 'medical_cause_definition_id', 'free_text', 'encryption_key_version', 'sort_order'])]
class MedicalSessionRootCause extends Model
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

    /** @return BelongsTo<MedicalSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(MedicalSession::class, 'medical_session_id');
    }

    /** @return BelongsTo<MedicalCauseDefinition, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(MedicalCauseDefinition::class, 'medical_cause_definition_id');
    }

    protected function casts(): array
    {
        return ['encryption_key_version' => 'integer', 'sort_order' => 'integer'];
    }
}
