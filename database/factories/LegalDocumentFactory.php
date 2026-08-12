<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    protected $model = LegalDocument::class;

    public function definition(): array
    {
        return [
            'document_type' => 'privacy',
            'purpose' => 'privacy_consent',
            'locale' => 'en',
            'management_mode' => LegalDocumentManagementMode::PlatformManaged->value,
            'status' => LegalDocumentStatus::Draft->value,
            'version' => '2026-08-12',
            'content' => 'Published legal content is supplied by the platform configuration.',
            'is_required' => true,
            'effective_at' => null,
            'published_at' => null,
            'archived_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (LegalDocument $document): LegalDocument => $document->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function published(): static
    {
        return $this->state([
            'status' => LegalDocumentStatus::Published->value,
            'effective_at' => now(),
            'published_at' => now(),
        ]);
    }
}
