<?php

namespace Database\Factories;

use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentSection> */
class ContentSectionFactory extends Factory
{
    protected $model = ContentSection::class;

    public function definition(): array
    {
        return [
            'section_key' => 'author',
            'locale' => 'en',
            'title' => 'Managed title',
            'body' => 'Managed body',
            'media' => null,
            'sort_order' => 0,
            'is_visible' => true,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ContentSection $section): ContentSection => $section->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_visible' => false]);
    }
}
