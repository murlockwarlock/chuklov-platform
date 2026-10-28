<?php

namespace App\Modules\Feedback\Application;

use App\Modules\Feedback\Domain\Models\FeedbackConfiguration;
use App\Modules\Feedback\Domain\Models\FeedbackReviewDestination;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Organizations\Application\OrganizationContext;

final class GetFeedbackConfiguration
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return array{enabled: bool, positiveThreshold: int, lowScoreFeedbackRequired: bool, reviewLinks: array{ru: string|null, en: string|null}, reviewDestinations: list<array{label: string, url: string, isActive: bool, sortOrder: int}>, feedbackThemes: list<array{name: string, groupName: string|null, isActive: bool, sortOrder: int}>} */
    public function handle(): array
    {
        $configuration = FeedbackConfiguration::query()
            ->where('organization_id', $this->context->id())
            ->first();
        /** @var list<array{label: string, url: string, isActive: bool, sortOrder: int}> $reviewDestinations */
        $reviewDestinations = FeedbackReviewDestination::query()
            ->where('organization_id', $this->context->id())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['label', 'url', 'is_active', 'sort_order'])
            ->map(static fn (FeedbackReviewDestination $destination): array => [
                'label' => (string) $destination->label,
                'url' => (string) $destination->url,
                'isActive' => (bool) $destination->is_active,
                'sortOrder' => (int) $destination->sort_order,
            ])
            ->values()
            ->all();
        $feedbackThemes = FeedbackTheme::query()
            ->where('organization_id', $this->context->id())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['name', 'group_name', 'is_active', 'sort_order'])
            ->map(static fn (FeedbackTheme $theme): array => [
                'name' => (string) $theme->name,
                'groupName' => $theme->group_name !== null ? (string) $theme->group_name : null,
                'isActive' => (bool) $theme->is_active,
                'sortOrder' => (int) $theme->sort_order,
            ])
            ->values()
            ->all();

        return [
            'enabled' => $configuration->enabled ?? true,
            'positiveThreshold' => $configuration->positive_threshold ?? 8,
            'lowScoreFeedbackRequired' => $configuration->low_score_feedback_required ?? true,
            'reviewLinks' => [
                'ru' => $configuration?->review_url_ru,
                'en' => $configuration?->review_url_en,
            ],
            'reviewDestinations' => $reviewDestinations,
            'feedbackThemes' => $feedbackThemes,
        ];
    }

    public function record(): ?FeedbackConfiguration
    {
        return FeedbackConfiguration::query()
            ->where('organization_id', $this->context->id())
            ->first();
    }
}
