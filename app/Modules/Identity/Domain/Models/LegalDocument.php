<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\LegalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property-read Organization $organization
 * @property LegalDocumentManagementMode $management_mode
 * @property LegalDocumentStatus $status
 * @property bool $is_required
 * @property Carbon|null $effective_at
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 */
#[Fillable(['document_type', 'purpose', 'locale', 'content'])]
class LegalDocument extends Model
{
    /** @use HasFactory<LegalDocumentFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->getRawOriginal('status') !== LegalDocumentStatus::Published->value) {
                return;
            }

            $allowedChanges = ['status', 'archived_at', 'updated_at'];
            $unsafeChanges = array_diff(array_keys($document->getDirty()), $allowedChanges);

            if ($unsafeChanges !== []) {
                throw new LogicException('Published legal document versions are immutable.');
            }
        });
    }

    protected static function newFactory(): LegalDocumentFactory
    {
        return LegalDocumentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'management_mode' => LegalDocumentManagementMode::class,
            'status' => LegalDocumentStatus::class,
            'is_required' => 'boolean',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
