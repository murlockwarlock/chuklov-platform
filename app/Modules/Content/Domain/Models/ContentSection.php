<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ContentSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read Organization $organization */
#[Fillable(['section_key', 'locale', 'title', 'body', 'media', 'sort_order'])]
class ContentSection extends Model
{
    /** @use HasFactory<ContentSectionFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function newFactory(): ContentSectionFactory
    {
        return ContentSectionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }
}
