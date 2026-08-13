<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use Database\Factories\NotificationTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property NotificationTemplateStatus $status
 * @property array<string> $variables
 * @property Carbon|null $published_at
 */
#[Fillable(['version', 'status', 'subject', 'body', 'variables', 'published_at'])]
class NotificationTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<NotificationTemplateVersionFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function newFactory(): NotificationTemplateVersionFactory
    {
        return NotificationTemplateVersionFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Notification template versions are immutable.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Notification template versions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => NotificationTemplateStatus::class,
            'variables' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
