<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['template_key', 'name', 'locale', 'purpose', 'is_active'])]
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<NotificationTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class, 'template_id');
    }

    /** @return HasOne<NotificationTemplateVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(NotificationTemplateVersion::class, 'template_id')->ofMany('version', 'max');
    }

    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
