<?php

namespace App\Modules\Attachments\Domain\Models;

use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int|null $client_id
 * @property int|null $uploaded_by_user_id
 * @property AttachmentType $attachment_type
 * @property string $disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256_checksum
 * @property AttachmentScanStatus $scan_status
 * @property array<string, mixed>|null $scan_result_metadata
 * @property Carbon|null $scanned_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Client|null $client
 * @property-read User|null $uploadedBy
 */
#[Fillable([
    'uuid',
    'organization_id',
    'client_id',
    'uploaded_by_user_id',
    'attachment_type',
    'disk',
    'storage_path',
    'original_filename',
    'mime_type',
    'size_bytes',
    'sha256_checksum',
    'scan_status',
    'scan_result_metadata',
    'scanned_at',
])]
class MedicalAttachment extends Model
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
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isAvailable(): bool
    {
        return $this->scan_status->isAvailable();
    }

    protected function casts(): array
    {
        return [
            'attachment_type' => AttachmentType::class,
            'scan_status' => AttachmentScanStatus::class,
            'scan_result_metadata' => 'array',
            'scanned_at' => 'datetime',
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
