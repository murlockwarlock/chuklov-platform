<?php

namespace App\Modules\Attachments\Domain\Models;

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
 * @property int $medical_attachment_id
 * @property string $channel
 * @property string $status
 * @property string $idempotency_key
 * @property int $attempt_count
 * @property string|null $provider_reference
 * @property string|null $error_code
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $fallback_url_expires_at
 */
#[Fillable([
    'organization_id',
    'client_id',
    'medical_attachment_id',
    'channel',
    'status',
    'idempotency_key',
    'attempt_count',
    'provider_reference',
    'error_code',
    'claimed_at',
    'delivered_at',
    'failed_at',
    'fallback_url_expires_at',
])]
class MedicalAttachmentDelivery extends Model
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

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'fallback_url_expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
