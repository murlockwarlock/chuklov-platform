<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $source */
#[Fillable(['organization_id', 'client_id', 'medical_attachment_id', 'source'])]
class ClientAvatarProfile extends Model
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

    /** @return BelongsTo<MedicalAttachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MedicalAttachment::class, 'medical_attachment_id');
    }
}
