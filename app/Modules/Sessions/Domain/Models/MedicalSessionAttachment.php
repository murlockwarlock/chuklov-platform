<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int $medical_session_id
 * @property int $medical_attachment_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read MedicalSession $session
 * @property-read MedicalAttachment $attachment
 */
#[Fillable([
    'organization_id',
    'client_id',
    'medical_session_id',
    'medical_attachment_id',
])]
class MedicalSessionAttachment extends Model
{
    /** @return BelongsTo<MedicalSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(MedicalSession::class, 'medical_session_id');
    }

    /** @return BelongsTo<MedicalAttachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MedicalAttachment::class, 'medical_attachment_id');
    }
}
