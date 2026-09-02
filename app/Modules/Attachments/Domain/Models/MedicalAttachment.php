<?php

namespace App\Modules\Attachments\Domain\Models;

use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Client|null $client
 * @property-read User|null $uploadedBy
 * @property-read Collection<int, MedicalSessionAttachment> $sessionLinks
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

    /** @return HasMany<MedicalSessionAttachment, $this> */
    public function sessionLinks(): HasMany
    {
        return $this->hasMany(MedicalSessionAttachment::class, 'medical_attachment_id');
    }

    protected function casts(): array
    {
        return [
            'attachment_type' => AttachmentType::class,
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
